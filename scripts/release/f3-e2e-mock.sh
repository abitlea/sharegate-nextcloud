#!/usr/bin/env bash
# F3: Mock E2E on Linux (or any host with curl)
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=nc-resolve.sh
source "$SCRIPT_DIR/nc-resolve.sh"

NC_URL="${NC_URL:?Set NC_URL e.g. http://192.168.128.128/nextcloud}"
NC_USER="${NC_USER:-admin}"
NC_PASSWORD="${NC_PASSWORD:?Set NC_PASSWORD}"
NC_PHP="${NC_PHP:-php}"
NC_OCC_USER="${NC_OCC_USER:-www-data}"
TEST_FILE="${TEST_FILE:-sharegate-e2e-test.txt}"

NC_URL="${NC_URL%/}"
APP_BASE="$NC_URL/index.php/apps/sharegate"
COOKIE_JAR="$(mktemp)"
trap 'rm -f "$COOKIE_JAR"' EXIT

step() { echo ""; echo "==> $*"; }

get_token() {
  grep -oP 'data-requesttoken="\K[^"]+' <<<"$1" | head -1 \
    || grep -oP '"requestToken"\s*:\s*"\K[^"]+' <<<"$1" | head -1 \
    || grep -oP "requestToken\s*:\s*'\K[^']+" <<<"$1" | head -1 \
    || true
}

occ() {
  local args=("$@")
  if [[ "$(id -un)" == "$NC_OCC_USER" ]]; then
    (cd "$NC_ROOT" && $NC_PHP occ "${args[@]}")
  elif command -v sudo >/dev/null 2>&1; then
    sudo -u "$NC_OCC_USER" -- bash -c "cd $(printf '%q' "$NC_ROOT") && $(printf '%q' "$NC_PHP") occ $(printf '%q ' "${args[@]}")"
  else
    (cd "$NC_ROOT" && $NC_PHP occ "${args[@]}")
  fi
}

new_test_file() {
  local cfg="${NC_CONFIG:-}"
  [[ -n "$cfg" && -f "$cfg" ]] || return 0
  local datadir
  datadir="$($NC_PHP -r "include '$cfg'; echo \$CONFIG['datadirectory'];")"
  local files_dir="$datadir/$NC_USER/files"
  mkdir -p "$files_dir"
  echo "ShareGate E2E test" >"$files_dir/$TEST_FILE"
  chown -R "$NC_OCC_USER:$NC_OCC_USER" "$files_dir/$TEST_FILE" 2>/dev/null || true
  occ files:scan "$NC_USER" >/dev/null
  echo "Test file: $files_dir/$TEST_FILE"
}

step "F3 Mock E2E ($NC_URL)"

if [[ -d "$NC_ROOT" ]]; then
  step "Create test file in user datadir"
  new_test_file
else
  echo "WARN: NC_ROOT not found; assume $TEST_FILE exists in $NC_USER files"
fi

step "Login ($NC_USER)"
LOGIN_HTML="$(curl -sf -c "$COOKIE_JAR" "$NC_URL/login")"
TOKEN="$(get_token "$LOGIN_HTML")"
curl -sf -b "$COOKIE_JAR" -c "$COOKIE_JAR" -X POST "$NC_URL/login" \
  -d "user=$NC_USER" \
  -d "password=$NC_PASSWORD" \
  -d "timezone=UTC" \
  -d "timezone_offset=0" \
  ${TOKEN:+-d "requesttoken=$TOKEN"} >/dev/null

DASH_HTML="$(curl -sf -b "$COOKIE_JAR" "$APP_BASE/")"
TOKEN="$(get_token "$DASH_HTML")"
[[ -n "$TOKEN" ]] || { echo "requesttoken not found after login" >&2; exit 1; }

step "Create paid share"
CREATE_JSON="$(curl -sf -b "$COOKIE_JAR" -X POST "$APP_BASE/share/create" \
  -H "requesttoken: $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "{\"file_path\":\"$TEST_FILE\",\"file_name\":\"$TEST_FILE\",\"title\":\"E2E Test\",\"price\":100,\"access_days\":7,\"storage_type\":\"nextcloud\"}")"
echo "$CREATE_JSON"
SHARE_ID="$(echo "$CREATE_JSON" | $NC_PHP -r 'echo json_decode(stream_get_contents(STDIN), true)["share_id"] ?? "";')"
[[ -n "$SHARE_ID" ]] || { echo "create share failed" >&2; exit 1; }
echo "share_id: $SHARE_ID"

BUYER_ID="e2e_buyer_$(openssl rand -hex 6 2>/dev/null || echo $$)"

step "Create Mock payment"
PAY_JSON="$(curl -sf -X POST "$APP_BASE/payment/create" \
  -H "Content-Type: application/json" \
  -d "{\"share_id\":\"$SHARE_ID\",\"provider_user_id\":\"$BUYER_ID\"}")"
ORDER_ID="$(echo "$PAY_JSON" | $NC_PHP -r 'echo json_decode(stream_get_contents(STDIN), true)["order_id"] ?? "";')"
[[ -n "$ORDER_ID" ]] || { echo "payment create failed: $PAY_JSON" >&2; exit 1; }
echo "order_id: $ORDER_ID"

step "Confirm Mock payment"
curl -sf -X POST "$APP_BASE/payment/webhook" \
  -H "Content-Type: application/json" \
  -d "{\"order_id\":\"$ORDER_ID\",\"provider_user_id\":\"$BUYER_ID\"}" >/dev/null

step "Verify download"
VERIFY_JSON="$(curl -sf -X POST "$APP_BASE/payment/verify" \
  -H "Content-Type: application/json" \
  -d "{\"share_id\":\"$SHARE_ID\",\"provider_user_id\":\"$BUYER_ID\"}")"
echo "$VERIFY_JSON"
echo "$VERIFY_JSON" | grep -q ACCESS_GRANTED || { echo "verify failed" >&2; exit 1; }

step "Save to cloud (logged-in user)"
SAVE_JSON="$(curl -sf -b "$COOKIE_JAR" -X POST "$APP_BASE/s/$SHARE_ID/save-to-cloud" \
  -H "requesttoken: $TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"provider_user_id\":\"$BUYER_ID\"}")"
echo "$SAVE_JSON"
echo "$SAVE_JSON" | grep -q '"success":true' || { echo "save-to-cloud failed" >&2; exit 1; }

echo ""
echo "[F3] PASS"
