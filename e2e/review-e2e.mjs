/**
 * GIZTOP Review Enhance - E2E harness (headless Chromium via playwright-core).
 *
 * Why a browser and not curl: product pages are served by Varnish, so the
 * `form_key` embedded in the HTML belongs to whichever session populated the
 * cache. Magento's form-key provider script refreshes that value client side and
 * drops a `form_key` cookie, which is what makes a real submission valid. curl
 * executes no JavaScript and therefore keeps the stale cached value -> every
 * POST fails with "Invalid Form Key". A browser is the only faithful client for
 * this flow.
 *
 * Usage (inside the Playwright container):
 *   node review-e2e.mjs --expect=success
 *   node review-e2e.mjs --expect=success --turnstile=pass
 *   node review-e2e.mjs --expect=rejected --turnstile=fail
 *
 * Prints one JSON line prefixed with "E2E_RESULT " so the shell wrapper can
 * parse it without depending on human-readable log format.
 */
import { chromium } from 'playwright-core';

const PRODUCT_PATH = process.env.PRODUCT_PATH || '/sample-product.html';
const BASE = process.env.BASE_URL || 'https://example.com';

const args = Object.fromEntries(
  process.argv.slice(2).map((a) => {
    const m = a.match(/^--([^=]+)=(.*)$/);
    return m ? [m[1], m[2]] : [a.replace(/^--/, ''), 'true'];
  })
);

const expect = args.expect || 'success';           // success | rejected
const turnstile = args.turnstile || 'off';         // off | pass | fail
const marker = args.marker || `e2e-${Date.now()}`;
const nickname = `${marker}@example.com`;
const detail = `E2E harness submission ${marker} - safe to delete`;

const SUCCESS_TEXT = 'You submitted your review for moderation.';

const result = {
  ok: false,
  expect,
  turnstile,
  marker,
  nickname,
  productUrl: `${BASE}${PRODUCT_PATH}`,
  httpStatus: null,
  formKeyPresent: false,
  formKeyCookie: false,
  turnstileWidgets: 0,
  message: '',
  postSeen: false,
  postUrl: '',
  postStatus: null,
  finalUrl: '',
  messagesAll: [],
  fieldValues: {},
  formErrors: [],
  error: '',
};


/**
 * Set a Knockout-bound field and make sure the value sticks.
 *
 * These inputs carry data-bind="value: ..." and Knockout writes the observable
 * back into the DOM whenever the binding refreshes, so a one-shot fill() or
 * type() can end up cleared ("This is a required field." on submit). Try the
 * human path first, then force the value through the DOM and re-assert.
 */
async function setField(page, selector, value) {
  for (let attempt = 0; attempt < 3; attempt++) {
    await page.click(selector);
    await page.keyboard.press('ControlOrMeta+a').catch(() => {});
    await page.keyboard.type(value, { delay: 5 });
    await page.keyboard.press('Tab').catch(() => {});
    let current = await page.inputValue(selector).catch(() => '');
    if (current === value) return true;

    await page.evaluate(
      ([sel, val]) => {
        const el = document.querySelector(sel);
        if (!el) return;
        el.value = val;
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
      },
      [selector, value]
    );
    current = await page.inputValue(selector).catch(() => '');
    if (current === value) return true;
    await page.waitForTimeout(500);
  }
  return false;
}

let browser;
try {
  browser = await chromium.launch({
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });
  const context = await browser.newContext();
  const page = await context.newPage();
  page.on('request', (r) => {
    if (r.method() === 'POST' && r.url().includes('/review/product/post/')) {
      result.postSeen = true;
      result.postUrl = r.url();
    }
  });
  page.on('response', (r) => {
    if (r.request().method() === 'POST' && r.url().includes('/review/product/post/')) {
      result.postStatus = r.status();
    }
  });

  const response = await page.goto(result.productUrl, {
    waitUntil: 'domcontentloaded',
    timeout: 60000,
  });
  result.httpStatus = response ? response.status() : null;

  // The form-key provider needs a moment; wait for the cookie rather than
  // guessing a fixed delay.
  await page.waitForFunction(
    () => {
      const input = document.querySelector('input[name=form_key]');
      return !!input && String(input.value).length >= 16;
    },
    null,
    { timeout: 20000 }
  );

  await page.waitForSelector('#review-form', { state: 'visible', timeout: 20000 });

  result.formKeyPresent = await page.evaluate(() => {
    const i = document.querySelector('input[name=form_key]');
    return !!i && String(i.value).length >= 16;
  });
  try {
    await page.waitForFunction(() => document.cookie.includes('form_key='), null, { timeout: 8000 });
  } catch (e) {
    /* informational only: the hidden input is what the server validates */
  }
  const cookies = await context.cookies();
  result.formKeyCookie = cookies.some((c) => c.name === 'form_key' && c.value);
  result.turnstileWidgets = await page.locator('.cf-turnstile').count();

  if (turnstile !== 'off') {
    // The widget must be present when the feature is enabled.
    await page.waitForSelector('.cf-turnstile', { state: 'attached', timeout: 20000 });
    // Cloudflare injects the response token into a hidden field once solved.
    // The test keys solve instantly; wait for the token or give up politely.
    try {
      await page.waitForFunction(
        () => {
          const el = document.querySelector('input[name="cf-turnstile-response"]');
          return !!el && String(el.value).length > 10;
        },
        null,
        { timeout: 25000 }
      );
    } catch (e) {
      // Always-fail keys never produce a usable token; that is expected and the
      // server side must reject. Do not fail the harness here.
    }
  }

  // The form is Knockout-bound; setField() retries until the value survives.
  const nickOk = await setField(page, '#nickname_field', nickname);
  const detailOk = await setField(page, '#review_field', detail);
  if (!nickOk || !detailOk) {
    throw new Error(`could not persist field values (nickname=${nickOk}, detail=${detailOk})`);
  }

  // Pick the highest star of the first rating dimension (Quality).
  // The theme hides the native radio inputs behind star labels, so a real click
  // is intercepted; set the state through the DOM and fire the events the
  // Magento validator listens to.
  const ratingApplied = await page.evaluate(() => {
    const all = Array.from(document.querySelectorAll('#review-form input[type=radio][name^="ratings["]'));
    if (!all.length) return { groups: 0, set: 0 };
    const byName = new Map();
    all.forEach((el) => {
      if (!byName.has(el.name)) byName.set(el.name, []);
      byName.get(el.name).push(el);
    });
    let set = 0;
    byName.forEach((inputs) => {
      const target = inputs[inputs.length - 1];
      target.checked = true;
      target.dispatchEvent(new Event('click', { bubbles: true }));
      target.dispatchEvent(new Event('change', { bubbles: true }));
      if (target.checked) set++;
    });
    return { groups: byName.size, set };
  });
  if (!ratingApplied.set || ratingApplied.set !== ratingApplied.groups) {
    throw new Error(`could not set every rating dimension (${JSON.stringify(ratingApplied)})`);
  }

  result.fieldValues = await page.evaluate(() => ({
    nickname: document.querySelector('#nickname_field')?.value || '',
    detailLength: (document.querySelector('#review_field')?.value || '').length,
    ratingsChecked: document.querySelectorAll('#review-form input[type=radio][name^="ratings["]:checked').length,
  }));

  await Promise.all([
    page.waitForLoadState('domcontentloaded', { timeout: 60000 }).catch(() => {}),
    page.click('#review-form button.action.submit'),
  ]);

  await page.waitForTimeout(1200);
  result.formErrors = await page
    .locator('#review-form .mage-error')
    .allInnerTexts()
    .catch(() => []);

  // The Knockout layer clears the observable-bound inputs and its validator then
  // blocks the click with "This is a required field." even though we typed the
  // values. This harness targets SERVER behaviour (form_key validation, the
  // Turnstile gate, review persistence), so when the click was swallowed we set
  // the DOM values one final time and submit the form natively.
  if (!result.postSeen) {
    await page
      .evaluate(
        ([nick, det]) => {
          const setVal = (sel, val) => {
            const el = document.querySelector(sel);
            if (!el) return;
            el.value = val;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
          };
          setVal('#nickname_field', nick);
          setVal('#review_field', det);
          const form = document.querySelector('#review-form');
          if (form) form.submit();
        },
        [nickname, detail]
      )
      .catch(() => {});
    await page.waitForTimeout(4000);
  }

  // Message may land in the DOM right after the redirect; give it a moment.
  try {
    await page.waitForSelector('.page.messages .message', { timeout: 20000 });
  } catch (e) {
    /* fall through: we still read whatever is there */
  }
  await page.waitForTimeout(1500);
  result.finalUrl = page.url();
  result.messagesAll = await page.locator('.message').allInnerTexts().catch(() => []);
  result.message = (await page.locator('.page.messages .message').allInnerTexts().catch(() => []))
    .join(' | ')
    .trim();

  const normalised = result.message.replace(/\s+/g, ' ').trim().toLowerCase();
  const gotSuccess = normalised.includes(SUCCESS_TEXT.toLowerCase());
  // Any of these means the server refused the submission on purpose:
  //  - missing token            -> "Please complete the human verification challenge..."
  //  - siteverify rejection     -> "We could not verify the human verification challenge..."
  //  - moderation / form key    -> their own messages
  const gotError =
    /invalid form key|human verification|not allowed|too long|could not verify/i.test(result.message);

  if (expect === 'success') {
    result.ok = gotSuccess;
    if (!gotSuccess) result.error = gotError ? 'rejected by server' : 'no success message';
  } else {
    // rejected: must NOT be a success, and must show an error message.
    result.ok = !gotSuccess && gotError;
    if (!result.ok) result.error = gotSuccess ? 'unexpectedly accepted' : 'no rejection message';
  }
} catch (e) {
  result.error = `${e.name}: ${String(e.message).slice(0, 200)}`;
} finally {
  if (browser) await browser.close().catch(() => {});
}

console.log('E2E_RESULT ' + JSON.stringify(result));
process.exit(result.ok ? 0 : 1);