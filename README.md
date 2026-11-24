# Magento 2 Review Enhance

Two production-grade additions to Magento 2's product review system:

1. **Cloudflare Turnstile anti-spam** — invisible human verification on the frontend review form. Most mobile users pass without interaction.
2. **Asynchronous review webhooks** — every new review is published to RabbitMQ and delivered to an HTTPS endpoint with HMAC signatures, so support teams can react immediately.

Built and validated against **Magento 2.4.9 CE / PHP 8.5** on a real store, then extracted here with environment-specific values removed.

---

## Why this module

Magento's native review flow has a few gaps that matter in production:

| Gap | What this module does |
|---|---|
| No captcha on the review form out of the box (unless you install the reCAPTCHA metapackage) | Adds Turnstile with a single plugin, no composer dependency |
| No way to notify an external system when a review arrives | Publishes an event to RabbitMQ, delivered with retries and a dead-letter queue |
| Nickname field doubles as an email field, with no masking on output | Masks emails in webhook payloads and in the GraphQL mapper |
| No moderation keyword/domain blacklist | Adds a configurable blacklist plugin (kept, optional) |
| Review title often empty | Auto-fills it from the review body |

---

## Requirements

- Magento **2.4.x** (developed and verified on 2.4.9 CE)
- PHP **8.1+** (verified on 8.5)
- For the webhook feature: a working **RabbitMQ** connection configured in `app/etc/env.php` (`queue.amqp`)
- For Turnstile: a Cloudflare account with a Turnstile site

---

## Installation

The module is a standard `app/code` module (not a composer package):

```bash
# from your Magento root
mkdir -p app/code/Giztop
cp -R path/to/app/code/Giztop/ReviewEnhance app/code/Giztop/

bin/magento setup:upgrade
bin/magento setup:static-content:deploy -f --jobs=4 en_US
bin/magento setup:di:compile
bin/magento cache:flush
systemctl reload php-fpm     # required when opcache.validate_timestamps=0
```

> **`setup:upgrade` and `setup:static-content:deploy` must run as a pair.**
> `setup:upgrade` clears `pub/static`; running it alone serves a broken storefront (500) until static content is redeployed.

The webhook feature additionally declares its RabbitMQ topology during `setup:upgrade` (via `Magento\Amqp\Setup\Recurring`), so the exchange and queues appear automatically.

### Consumer service (webhook)

Create a systemd unit so the consumer survives restarts:

```ini
# /etc/systemd/system/review-webhook-consumer.service
[Unit]
Description=Magento review webhook consumer
After=network-online.target
Wants=network-online.target
StartLimitIntervalSec=0

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/magento
ExecStart=/usr/bin/php /var/www/magento/bin/magento queue:consumers:start giztop.review.webhook --max-messages=1000
Restart=always
RestartSec=5
Nice=10
MemoryMax=512M

[Install]
WantedBy=multi-user.target
```

```bash
systemctl daemon-reload
systemctl enable --now review-webhook-consumer
```

> The consumer caches configuration. **Restart it after every configuration change**, otherwise it keeps reading the old values.

---

## Configuration

Both features are **disabled by default** and change nothing until enabled.

### Turnstile

`Stores > Configuration > Giztop > Review Enhance > Human Verification (Cloudflare Turnstile)`

| Field | Notes |
|---|---|
| Enabled | Turn the check on |
| Site Key / Secret Key | From your Cloudflare Turnstile dashboard. The secret is stored encrypted. |
| Theme | `auto` / `light` / `dark` |
| Size | `flexible` recommended for mobile |
| Fail Mode | `open` = allow submission when Cloudflare is unreachable (logged as warning); `closed` = reject |
| Expected Hostname | **Leave empty.** Empty means "use the store base URL host", which is the strict production behaviour. Only set it for testing. |

The feature stays completely inert unless `enabled`, `site_key` and `secret_key` are all set.

### Webhook

`Stores > Configuration > Giztop > Review Enhance > Review Webhook`

| Field | Default | Notes |
|---|---|---|
| Enabled | `0` | |
| Delivery Channel | `generic` | `generic` = raw JSON + `X-Giztop-Signature`; `feishu` = interactive card for a Feishu/Lark custom bot |
| Endpoint URL | — | Must be `https://`. Private/reserved addresses are rejected. |
| Secret | — | Used for HMAC signing. Stored encrypted. |
| Timeout | `10` | Seconds |
| Max Attempts | `5` | After that the event is parked in the error queue |
| Include Review Detail | `1` | |
| Detail Max Length | `1000` | |
| Include Reviewer Email | `0` | Keep off to avoid sending PII |
| User Agent | `Giztop-ReviewEnhance/1.0` | |

---

## Feishu / Lark channel

Set **Delivery Channel = Feishu / Lark custom bot** to post an interactive card instead of raw JSON.
The Signing Secret field is then used as the **bot secret** (Feishu's own signature), not only for
`X-Giztop-Signature`.

### Signing

Feishu expects a `timestamp` + `sign` pair in the body:

```
string_to_sign = "<timestamp>\n<secret>"
sign           = base64( HMAC-SHA256( key = string_to_sign, data = "" ) )
```

Note the unusual part: the HMAC **key** is the string-to-sign and the message is empty. This is
different from the generic channel, which signs the raw body with the secret as key.

### Card content is editable in the admin

`Stores > Configuration > Giztop > Review Enhance > Review Webhook` exposes a title and a body
template. Both support these placeholders:

| Placeholder | Value |
|---|---|
| `{{product_name}}` `{{product_sku}}` `{{product_url}}` | Product identity and link |
| `{{rating}}` | This review's overall score (mean of its rating dimensions), e.g. `4.7` |
| `{{product_rating}}` | The product's aggregate rating in stars, e.g. `4.5` |
| `{{review_title}}` `{{review_detail}}` | Review text (detail already truncated per settings) |
| `{{status}}` `{{status_label}}` | `pending` / `approved` / `not_approved` and a human-readable label |
| `{{nickname}}` | Masked reviewer identity (`j***@g***.com`) |
| `{{review_id}}` `{{created_at}}` `{{store_code}}` | Metadata |
| `{{admin_url}}` | Review edit URL; also rendered as an "Open in admin" button under the card |

Default body:

```
**{{product_name}}**
Rating: {{rating}} / 5    Status: {{status_label}}
From: {{nickname}}    {{created_at}}

{{review_detail}}

[View product]({{product_url}})
```

The header colour follows the review status: pending = orange, approved = green, not approved = red.

### Success detection

Feishu answers **HTTP 200 even when the request fails** — the real result is in the body
(`{"code":0}` means success). The consumer therefore parses the response and treats any non-zero
`code` as a delivery failure, so it is retried and logged instead of being silently recorded as sent.

## Webhook contract

### Request

```
POST <your endpoint>
Content-Type: application/json
X-Giztop-Event: review.submitted
X-Giztop-Event-Id: <uuid v4>          # idempotency key, stable across retries
X-Giztop-Timestamp: <unix seconds>
X-Giztop-Signature: sha256=<hex>
User-Agent: Giztop-ReviewEnhance/1.0
```

### Body

```json
{
  "event": "review.submitted",
  "event_id": "2eac4cdf-…",
  "delivery_attempt": 1,
  "occurred_at": "2026-11-24T03:00:00+08:00",
  "store": { "id": 1, "code": "default" },
  "review": {
    "id": 411800,
    "status_id": 2,
    "status_code": "Pending",
    "title": "Great case",
    "detail": "Fits perfectly…",
    "detail_truncated": false,
    "nickname_masked": "j***@g***.com",
    "has_email": true,
    "created_at": "2026-11-24 03:00:00"
  },
  "product": { "id": 123, "sku": "SKU-1", "name": "Product", "url": "https://…" },
  "ratings": [ { "code": "Rating", "value": 5, "label": "5 stars" } ],
  "admin_url": "https://…/admin/review/product/post/id/411800/"
}
```

### Verifying the signature

Compute the HMAC over the **raw request body bytes**, not over a re-encoded JSON object (key ordering would change and the signature would never match).

```php
$rawBody   = file_get_contents('php://input');
$expected  = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
$received  = $_SERVER['HTTP_X_GIZTOP_SIGNATURE'] ?? '';

if (!hash_equals($expected, $received)) {
    http_response_code(401);
    exit;
}
```

```python
import hmac, hashlib
expected = "sha256=" + hmac.new(secret.encode(), raw_body, hashlib.sha256).hexdigest()
if not hmac.compare_digest(expected, request.headers["X-Giztop-Signature"]):
    return Response(status=401)
```

### Delivery semantics

- **At-least-once.** Retries reuse the same `event_id`; your endpoint must deduplicate on it.
- Backoff is exponential (`min(2^n, 30)` seconds) between attempts.
- After `max_attempts` the message is published to the `giztop.review.webhook.error` queue and logged at error level.
- If RabbitMQ is unavailable at publish time, **the review is still saved** — the publish failure is swallowed and logged. The event is not replayed later.

---

## How it works

```
Review::save()
  → beforeSave plugin snapshots isObjectNew (afterSave resets it)
  → DB transaction commits
  → review_save_commit_after observer
       → builds payload → publishes to RabbitMQ exchange giztop.review
  → queue giztop.review.webhook
  → consumer: HTTP POST + HMAC → 2xx = ack, otherwise retry / park
```

Key design points:

- **Publish after commit**, not in `afterSave`, so a rolled-back transaction cannot emit a phantom webhook.
- **`is_new` captured in `beforeSave`** because `AbstractDb::saveNewObject()` resets `isObjectNew()` before `afterSave` runs.
- **Publish failures are isolated** (`catch (\Throwable)`) — a broken broker never breaks review submission.
- **Retries are implemented in the consumer** because Magento's `Consumer` rejects (drops) a message when the handler throws; there is no built-in retry.
- **Turnstile failures never write to the database** — the plugin short-circuits with an error message and a redirect.

---

## E2E harness

`e2e/` contains a Playwright-based harness that drives a real browser against the storefront. It was written because curl cannot exercise the form: Magento's cached product pages carry a `form_key` belonging to whichever session rendered the cached copy, so a stateless client can never pass `form_key` validation.

```bash
cd e2e
export BASE_URL=https://your-store.test
export MAGENTO_ROOT=/var/www/magento
export DB_USER=magento DB_NAME=magento DB_PASSWORD=…   # or read from env.php

./run-review-e2e.sh success           # plain submission
./run-review-e2e.sh turnstile-pass    # Cloudflare test key that always passes
./run-review-e2e.sh turnstile-fail    # test key that always blocks (asserts nothing is written)
./run-webhook-e2e.sh                  # MQ → consumer → local HTTPS mock, verifies the signature
```

Notes:

- Uses Cloudflare's public test keys, so no Turnstile account is needed.
- The `turnstile-*` scenarios **overwrite** the Turnstile config. The script refuses to run them when a real site key is configured unless `FORCE=1` is set; the `success` scenario never touches config.
- **Run one scenario at a time.** The cleanup phase deletes global config paths; two concurrent runs will delete each other's configuration and can produce false passes.
- The harness reads DB credentials from `app/etc/env.php` inside a PHP process, so they never appear in `argv` or logs.

---

## Known limitations

| ID | Limitation | Impact |
|---|---|---|
| O-1 | If Cloudflare returns an empty `hostname`/`action`, the check is skipped | Defence-in-depth gap; requires a forged Cloudflare response to exploit |
| O-3 | Retry backoff `sleep()` blocks the single consumer for up to 30 s | Sustained failures can back up the queue; use a delayed queue or multiple consumers for high volume |
| R-C | Events are lost if RabbitMQ is down at publish time | Deliberate trade-off: review submission must never fail. Add a local outbox if you need zero loss |
| — | GraphQL `createProductReview` is not protected by Turnstile | Requires a customer token, so the risk is lower |
| — | The error queue has no consumer | Failed events need manual draining; add monitoring |

---

## Security notes

- Webhook secrets and Turnstile secrets use Magento's encrypted config backend.
- Endpoint URLs are validated twice (on save and before sending): HTTPS only, no redirects, private/reserved ranges rejected including `169.254/16` and `100.64/10`.
- Logs never contain tokens, secrets, or plaintext email addresses; IPs are masked.
- Reviewer emails are masked in webhook payloads (`j***@g***.com`).
- `allow_private_endpoint` is intentionally **not** exposed in the admin UI — it can only be set through `env.php` or a CLI config write, and exists solely for local E2E testing.

---

## License

MIT — see [LICENSE](LICENSE).

This module was extracted from a production store. Store names, hostnames, credentials and customer data have been removed.