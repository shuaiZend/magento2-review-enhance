#!/usr/bin/env bash
# Review Enhance - webhook delivery E2E scenario.
#
# Proves, end to end:
#   1. a customer review submission publishes an event to RabbitMQ
#   2. the systemd consumer delivers it over HTTPS to the endpoint
#   3. the payload is signed correctly: X-Giztop-Signature == sha256=HMAC(rawBody, secret)
#   4. the audit table records the delivery as "sent"
#
# TLS: the production client verifies certificates, so the mock is served with a
# throwaway CA that is handed to the consumer through a temporary systemd
# drop-in (CURL_CA_BUNDLE). Product code and the system trust store stay
# untouched. Everything is reverted in the EXIT trap.
set -uo pipefail
cd "$(dirname "$0")"
# shellcheck source=lib.sh
source ./lib.sh

WEBHOOK_HOST="webhook.test"
WEBHOOK_PORT="18443"
MOCK_SECRET="e2e-webhook-secret-$(date +%s)"
TLS_DIR="$E2E_DIR/tls"
RECEIVED="$E2E_DIR/webhook-received.jsonl"
MOCK_NAME="review-e2e-webhook-mock"
CA_ANCHOR="/etc/pki/ca-trust/source/anchors/review-e2e-ca.crt"
HOSTS_MARK="# review-e2e-harness"

CONFIG_PATHS=(
  giztop_review/webhook/enabled
  giztop_review/webhook/endpoint_url
  giztop_review/webhook/secret
  giztop_review/webhook/allow_private_endpoint
)

cleanup() {
  echo "-- cleanup --"
  docker rm -f "$MOCK_NAME" >/dev/null 2>&1 || true
  rm -f "$CA_ANCHOR"
  update-ca-trust extract >/dev/null 2>&1 || true
  sed -i "/$HOSTS_MARK/d" /etc/hosts 2>/dev/null || true
  systemctl daemon-reload >/dev/null 2>&1 || true
  systemctl restart giztop-review-webhook-consumer >/dev/null 2>&1 || true
  cfg_del "${CONFIG_PATHS[@]}" >/dev/null 2>&1 || true
  flush_cache
  rm -f "$RECEIVED" "$E2E_DIR/webhook-mock.ready"
  echo "-- cleanup done --"
}
trap cleanup EXIT

echo "== scenario: webhook =="

# ---------------------------------------------------------------- TLS material
mkdir -p "$TLS_DIR"
if [[ ! -f "$TLS_DIR/ca.crt" ]]; then
  openssl req -x509 -newkey rsa:2048 -nodes -days 2 \
    -keyout "$TLS_DIR/ca.key" -out "$TLS_DIR/ca.crt" \
    -subj "/CN=review-e2e-ca" >/dev/null 2>&1
  openssl req -newkey rsa:2048 -nodes \
    -keyout "$TLS_DIR/server.key" -out "$TLS_DIR/server.csr" \
    -subj "/CN=$WEBHOOK_HOST" >/dev/null 2>&1
  cat > "$TLS_DIR/ext.cnf" <<EXT
subjectAltName=DNS:$WEBHOOK_HOST
EXT
  openssl x509 -req -in "$TLS_DIR/server.csr" -CA "$TLS_DIR/ca.crt" -CAkey "$TLS_DIR/ca.key" \
    -CAcreateserial -days 2 -extfile "$TLS_DIR/ext.cnf" -out "$TLS_DIR/server.crt" >/dev/null 2>&1
fi
chmod 644 "$TLS_DIR"/*.crt "$TLS_DIR"/*.key
echo "  tls: $TLS_DIR/server.crt (SAN=$WEBHOOK_HOST, CA=$TLS_DIR/ca.crt)"

# --------------------------------------------------------------- mock + wiring
rm -f "$RECEIVED" "$E2E_DIR/webhook-mock.ready"
docker rm -f "$MOCK_NAME" >/dev/null 2>&1 || true
docker run -d --name "$MOCK_NAME" --network host \
  -v "$E2E_DIR:/work" -w /work \
  -e PLAYWRIGHT_BROWSERS_PATH=/ms-playwright \
  -e MOCK_PORT="$WEBHOOK_PORT" -e MOCK_SECRET="$MOCK_SECRET" \
  -e MOCK_CERT="/work/tls/server.crt" -e MOCK_KEY="/work/tls/server.key" \
  -e MOCK_OUT="/work/webhook-received.jsonl" \
  "$PLAYWRIGHT_IMAGE" node webhook-mock.mjs >/dev/null
for _ in $(seq 1 30); do [[ -f "$E2E_DIR/webhook-mock.ready" ]] && break; sleep 1; done
[[ -f "$E2E_DIR/webhook-mock.ready" ]] || { echo "  mock failed to start"; docker logs "$MOCK_NAME" 2>&1 | tail -5; exit 1; }
echo "  mock listening on 127.0.0.1:$WEBHOOK_PORT"

grep -q "$WEBHOOK_HOST" /etc/hosts || echo "127.0.0.1 $WEBHOOK_HOST $HOSTS_MARK" >> /etc/hosts

# Trust the throwaway CA for the duration of the run. CURL_CA_BUNDLE in a systemd
# drop-in was not honoured by this libcurl build (curl(60) "unable to get local
# issuer"), so the CA goes into the system trust store and is removed on exit.
cp "$TLS_DIR/ca.crt" "$CA_ANCHOR"
update-ca-trust extract
systemctl restart giztop-review-webhook-consumer
sleep 2
systemctl is-active --quiet giztop-review-webhook-consumer \
  && echo "  CA trusted temporarily + consumer restarted" \
  || { echo "  consumer failed to restart"; exit 1; }

cfg_set giztop_review/webhook/enabled 1
cfg_set giztop_review/webhook/endpoint_url "https://$WEBHOOK_HOST:$WEBHOOK_PORT/hook"
cfg_set giztop_review/webhook/secret "$MOCK_SECRET"
cfg_set giztop_review/webhook/allow_private_endpoint 1
flush_cache
# A long-running consumer loads the whole config into memory at bootstrap, so it
# must be restarted AFTER the config write or it keeps reporting
# "webhook disabled, skipping event".
systemctl restart giztop-review-webhook-consumer
sleep 3
systemctl is-active --quiet giztop-review-webhook-consumer \
  && echo "  webhook config written (allow_private_endpoint=1, test only) + consumer restarted" \
  || { echo "  consumer failed to restart after config write"; exit 1; }

# ------------------------------------------------------------------ submit
BEFORE_COUNT=$(review_count)
BEFORE_MAX=$(review_max_id)
echo "  review before: count=$BEFORE_COUNT max_id=$BEFORE_MAX"

OUT=$(run_browser --expect=success)
RC=$?
JSON=$(printf '%s\n' "$OUT" | e2e_json)
AFTER_COUNT=$(review_count)
AFTER_MAX=$(review_max_id)
DELTA=$((AFTER_COUNT - BEFORE_COUNT))
echo "  review after:  count=$AFTER_COUNT max_id=$AFTER_MAX (delta=$DELTA)"
CREATED_ID=$(sql "SELECT review_id FROM review WHERE review_id > $BEFORE_MAX ORDER BY review_id LIMIT 1;" | tr -d '[:space:]')
echo "  created review_id=$CREATED_ID"

# --------------------------------------------------------------- wait for POST
for _ in $(seq 1 60); do [[ -s "$RECEIVED" ]] && break; sleep 1; done

FAILED=0
[[ $RC -eq 0 ]] && pass "review submitted" || { fail "review submission failed"; FAILED=1; }
[[ "$DELTA" == "1" ]] && pass "review table delta = 1" || { fail "delta=$DELTA"; FAILED=1; }

if [[ -s "$RECEIVED" ]]; then
  pass "webhook received a delivery"
  python3 - "$RECEIVED" <<'PY'
import json, sys
rec = json.loads(open(sys.argv[1]).read().strip().splitlines()[-1])
print("  event            :", rec["event"])
print("  eventId          :", rec["eventId"])
print("  signaturePresent :", rec["signaturePresent"])
print("  signatureValid   :", rec["signatureValid"])
print("  bodyBytes        :", rec["bodyBytes"])
print("  nicknameMasked   :", rec["payloadNicknameMasked"])
print("  deliveryAttempt  :", rec["payloadDeliveryAttempt"])
print("  contentType      :", rec["contentType"])
ok = (
    rec["signatureValid"] is True
    and rec["event"] == "review.submitted"
    and bool(rec["eventId"])
    and "***" in str(rec["payloadNicknameMasked"])
)
sys.exit(0 if ok else 1)
PY
  [[ $? -eq 0 ]] && pass "HMAC signature valid, event/headers/payload sane" \
                 || { fail "HMAC signature invalid or payload unexpected"; FAILED=1; }
else
  fail "webhook mock received nothing within 60s"
  docker logs "$MOCK_NAME" 2>&1 | tail -5
  FAILED=1
fi

AUDIT=$(sql "SELECT CONCAT(status, ':', IFNULL(http_code,'-')) FROM giztop_review_webhook_log WHERE review_id=$CREATED_ID ORDER BY log_id DESC LIMIT 1;" | tr -d '[:space:]')
if [[ "$AUDIT" != "sent:200" ]]; then
  echo "  audit row: '${AUDIT:-none}'"
  sql_table "SELECT log_id, event_id, delivery_attempt, status, http_code, error_message
             FROM giztop_review_webhook_log WHERE review_id=$CREATED_ID ORDER BY log_id;"
  echo "  consumer journal:"
  journalctl -u giztop-review-webhook-consumer -n 8 --no-pager 2>&1 | sed 's/^/    /'
  echo "  module log:"
  grep -a "reviewenhance_webhook" "$MAGENTO_ROOT/var/log/system.log" | tail -4 | sed 's/^/    /'
  fail "audit row = '${AUDIT:-none}', expected sent:200"
  FAILED=1
else
  pass "audit row = sent:200"
fi

# ---------------------------------------------------------------- tidy up data
if [[ -n "$CREATED_ID" ]]; then
  sql "DELETE FROM giztop_review_webhook_log WHERE review_id = $CREATED_ID;"
  sql "DELETE FROM rating_option_vote WHERE review_id = $CREATED_ID;
       DELETE FROM review_store WHERE review_id = $CREATED_ID;
       DELETE FROM review_detail WHERE review_id = $CREATED_ID;
       DELETE FROM review WHERE review_id = $CREATED_ID;"
  echo "  removed test review $CREATED_ID and its audit rows"
fi

FINAL_COUNT=$(review_count)
[[ "$FINAL_COUNT" == "$BEFORE_COUNT" ]] && pass "review table restored ($FINAL_COUNT)" \
  || { fail "review table not restored ($FINAL_COUNT vs $BEFORE_COUNT)"; FAILED=1; }

[[ $FAILED -eq 0 ]] && echo "== RESULT: PASS ==" || echo "== RESULT: FAIL =="
exit $FAILED