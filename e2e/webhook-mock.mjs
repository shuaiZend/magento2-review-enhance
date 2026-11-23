/**
 * Review Enhance - HTTPS webhook mock receiver for the E2E harness.
 *
 * The production consumer enforces TLS verification (CURLOPT_SSL_VERIFYPEER=1),
 * so a plain self-signed mock would be rejected. This server therefore uses a
 * certificate issued by a throwaway CA that is handed to the consumer through a
 * temporary systemd drop-in (CURL_CA_BUNDLE), which keeps the product code and
 * the system trust store untouched.
 *
 * It writes one JSON record per delivery so the shell wrapper can assert:
 *   - the request actually arrived (delivery works end to end)
 *   - X-Giztop-* headers are present
 *   - X-Giztop-Signature equals sha256=HMAC_SHA256(rawBody, secret)
 *
 * Env: MOCK_PORT (default 8443), MOCK_SECRET, MOCK_CERT, MOCK_KEY, MOCK_OUT
 */
import { createServer } from 'node:https';
import { readFileSync, writeFileSync, appendFileSync } from 'node:fs';
import { createHmac, timingSafeEqual } from 'node:crypto';

const port = Number(process.env.MOCK_PORT || 8443);
const secret = process.env.MOCK_SECRET || '';
const certPath = process.env.MOCK_CERT;
const keyPath = process.env.MOCK_KEY;
const outPath = process.env.MOCK_OUT || '/work/webhook-received.jsonl';

if (!certPath || !keyPath) {
  console.error('MOCK_CERT and MOCK_KEY are required');
  process.exit(2);
}

const server = createServer(
  { cert: readFileSync(certPath), key: readFileSync(keyPath) },
  (req, res) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => {
      const raw = Buffer.concat(chunks);
      const signature = String(req.headers['x-giztop-signature'] || '');
      const expected = 'sha256=' + createHmac('sha256', secret).update(raw).digest('hex');

      let signatureValid = false;
      try {
        signatureValid =
          signature.length === expected.length &&
          timingSafeEqual(Buffer.from(signature), Buffer.from(expected));
      } catch (e) {
        signatureValid = false;
      }

      let payload = null;
      try {
        payload = JSON.parse(raw.toString('utf8'));
      } catch (e) {
        payload = null;
      }

      const record = {
        receivedAt: new Date().toISOString(),
        method: req.method,
        path: req.url,
        event: req.headers['x-giztop-event'] || null,
        eventId: req.headers['x-giztop-event-id'] || null,
        timestamp: req.headers['x-giztop-timestamp'] || null,
        userAgent: req.headers['user-agent'] || null,
        contentType: req.headers['content-type'] || null,
        signaturePresent: signature.length > 0,
        signatureValid,
        bodyBytes: raw.length,
        payloadEvent: payload && payload.event,
        payloadEventId: payload && payload.event_id,
        payloadDeliveryAttempt: payload && payload.delivery_attempt,
        payloadNicknameMasked: payload && payload.review && payload.review.nickname_masked,
        payloadHasEmail: payload && payload.review && payload.review.has_email,
        payloadProductSku: payload && payload.product && payload.product.sku,
      };

      appendFileSync(outPath, JSON.stringify(record) + '\n');
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ ok: true, signatureValid }));
    });
  }
);

server.listen(port, '127.0.0.1', () => {
  writeFileSync('/work/webhook-mock.ready', String(process.pid));
  console.log(`webhook mock listening on 127.0.0.1:${port}`);
});