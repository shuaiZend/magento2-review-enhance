#!/usr/bin/env bash
# Shared helpers for the Review Enhance E2E harness.
# Runs on the Magento host (not inside the container).
set -uo pipefail

MAGENTO_ROOT="${MAGENTO_ROOT:-/var/www/magento}"
PHP_BIN="${PHP_BIN:-php}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_USER="${DB_USER:-magento}"
DB_NAME="${DB_NAME:-magento}"
E2E_DIR="${E2E_DIR:-/tmp/e2e}"
PLAYWRIGHT_IMAGE="${PLAYWRIGHT_IMAGE:-mcr.microsoft.com/playwright:v1.65.1-jammy}"
PRODUCT_PATH="${PRODUCT_PATH:-/sample-product.html}"
BASE_URL="${BASE_URL:-https://example.com}"

# Credentials are read from env.php inside a php process and handed to mysql via
# the environment; they never appear in argv or on stdout.
sql() {
  local query="$1"
  ( cd "$MAGENTO_ROOT" && PW=$("$PHP_BIN" -r '$c=include "app/etc/env.php"; echo $c["db"]["connection"]["default"]["password"];') \
      MYSQL_PWD="$PW" mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" -N -B -e "$query" )
}

sql_table() {
  local query="$1"
  ( cd "$MAGENTO_ROOT" && PW=$("$PHP_BIN" -r '$c=include "app/etc/env.php"; echo $c["db"]["connection"]["default"]["password"];') \
      MYSQL_PWD="$PW" mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" -t -e "$query" )
}

review_count() { sql "SELECT COUNT(*) FROM review;" | tr -d '[:space:]'; }
review_max_id() { sql "SELECT MAX(review_id) FROM review;" | tr -d '[:space:]'; }

# Exact-path config writers. NEVER use LIKE here: a previous round wiped
# unrelated rows with a prefix match.
cfg_set() {
  local path="$1" value="$2"
  sql "INSERT INTO core_config_data (scope, scope_id, path, value)
       VALUES ('default', 0, '$path', '$value')
       ON DUPLICATE KEY UPDATE value = VALUES(value);"
}

cfg_del() {
  local path
  for path in "$@"; do
    sql "DELETE FROM core_config_data WHERE scope='default' AND scope_id=0 AND path='$path';"
  done
}

cfg_get() {
  local path="$1"
  sql "SELECT value FROM core_config_data WHERE scope='default' AND scope_id=0 AND path='$path';"
}

magento() {
  ( cd "$MAGENTO_ROOT" && "$PHP_BIN" -d memory_limit=-1 bin/magento "$@" )
}

flush_cache() { magento cache:flush >/dev/null 2>&1; }

run_browser() {
  # $@ = harness args (e.g. --expect=success --turnstile=pass)
  docker run --rm --network host \
    -v "$E2E_DIR:/work" -w /work \
    -e PLAYWRIGHT_BROWSERS_PATH=/ms-playwright \
    -e BASE_URL="$BASE_URL" -e PRODUCT_PATH="$PRODUCT_PATH" \
    "$PLAYWRIGHT_IMAGE" node review-e2e.mjs "$@" 2>&1
}

# Extract the JSON payload from the harness output.
e2e_json() {
  sed -n 's/^E2E_RESULT //p' | tail -1
}

json_field() {
  # $1 = json, $2 = field
  printf '%s' "$1" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d.get(sys.argv[1],""))' "$2" 2>/dev/null
}

pass() { printf '  \033[32mPASS\033[0m %s\n' "$*"; }
fail() { printf '  \033[31mFAIL\033[0m %s\n' "$*"; }