#!/usr/bin/env bash
# Review Enhance - review submission E2E scenarios.
#
#   ./run-review-e2e.sh success              # turnstile disabled: must be accepted
#   ./run-review-e2e.sh turnstile-pass       # CF always-pass test keys: accepted
#   ./run-review-e2e.sh turnstile-fail       # CF always-fail test keys: rejected, no row
#
# Every scenario asserts both the browser outcome and the review table delta.
set -uo pipefail
cd "$(dirname "$0")"
# shellcheck source=lib.sh
source ./lib.sh

SCENARIO="${1:-success}"

# Cloudflare public test keys (documented, no real account needed).
CF_PASS_SITE="1x00000000000000000000AA"
CF_PASS_SECRET="1x0000000000000000000000000000000AA"
CF_FAIL_SITE="2x00000000000000000000AB"
CF_FAIL_SECRET="2x0000000000000000000000000000000AA"

TURNSTILE_PATHS=(
  giztop_review/turnstile/enabled
  giztop_review/turnstile/site_key
  giztop_review/turnstile/secret_key
  giztop_review/turnstile/expected_hostname
)

cleanup_turnstile() {
  cfg_del "${TURNSTILE_PATHS[@]}" >/dev/null
  flush_cache
}

enable_turnstile() {
  local site="$1" secret="$2"
  cfg_set giztop_review/turnstile/enabled 1
  cfg_set giztop_review/turnstile/site_key "$site"
  cfg_set giztop_review/turnstile/secret_key "$secret"
  # Cloudflare test keys always answer hostname "example.com"; without this the
  # strict store-host check would reject a legitimately passed token.
  cfg_set giztop_review/turnstile/expected_hostname example.com
  flush_cache
}

echo "== scenario: $SCENARIO =="

case "$SCENARIO" in
  success)
    EXPECT=success
    HARNESS_ARGS=(--expect=success)
    EXPECT_DELTA=1
    ;;
  turnstile-pass)
    EXPECT=success
    HARNESS_ARGS=(--expect=success --turnstile=pass)
    EXPECT_DELTA=1
    enable_turnstile "$CF_PASS_SITE" "$CF_PASS_SECRET"
    ;;
  turnstile-fail)
    EXPECT=rejected
    HARNESS_ARGS=(--expect=rejected --turnstile=fail)
    EXPECT_DELTA=0
    enable_turnstile "$CF_FAIL_SITE" "$CF_FAIL_SECRET"
    ;;
  *)
    echo "unknown scenario: $SCENARIO" >&2
    exit 2
    ;;
esac

BEFORE_COUNT=$(review_count)
BEFORE_MAX=$(review_max_id)
echo "  review before: count=$BEFORE_COUNT max_id=$BEFORE_MAX"

OUT=$(run_browser "${HARNESS_ARGS[@]}")
RC=$?
echo "$OUT" | grep -v '^E2E_RESULT ' | sed 's/^/  [browser] /'
JSON=$(printf '%s\n' "$OUT" | e2e_json)

AFTER_COUNT=$(review_count)
AFTER_MAX=$(review_max_id)
DELTA=$((AFTER_COUNT - BEFORE_COUNT))
echo "  review after:  count=$AFTER_COUNT max_id=$AFTER_MAX (delta=$DELTA)"
echo "  harness json:  $JSON"

FAILED=0
[[ $RC -eq 0 ]] && pass "browser asserted: $EXPECT" || { fail "browser assertion failed (rc=$RC)"; FAILED=1; }
[[ "$DELTA" == "$EXPECT_DELTA" ]] && pass "review table delta = $EXPECT_DELTA" \
  || { fail "review table delta = $DELTA, expected $EXPECT_DELTA"; FAILED=1; }

if [[ "$SCENARIO" == turnstile-* ]]; then
  WIDGETS=$(json_field "$JSON" turnstileWidgets)
  [[ "${WIDGETS:-0}" -ge 1 ]] && pass "cf-turnstile widget rendered ($WIDGETS)" \
    || { fail "cf-turnstile widget missing"; FAILED=1; }
fi

# Record what was created so the caller can clean up by exact id.
if [[ "$DELTA" -gt 0 && -n "$AFTER_MAX" ]]; then
  echo "  created_review_ids: $(sql "SELECT review_id FROM review WHERE review_id > $BEFORE_MAX ORDER BY review_id;" | tr '\n' ' ')"
fi

cleanup_turnstile
[[ $FAILED -eq 0 ]] && echo "== RESULT: PASS ==" || echo "== RESULT: FAIL =="
exit $FAILED