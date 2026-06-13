#!/usr/bin/env bash
# F2: Deploy verify on Linux NC server
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
# shellcheck source=nc-resolve.sh
source "$SCRIPT_DIR/nc-resolve.sh"

NC_PHP="${NC_PHP:-php}"
NC_OCC_USER="${NC_OCC_USER:-www-data}"

step() { echo ""; echo "==> $*"; }

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

if [[ ! -f "$NC_ROOT/occ" ]]; then
  echo "occ not found. Set NC_ROOT to the folder containing occ (e.g. /opt/nextcloud/html)" >&2
  exit 1
fi

if ! nc_occ_ready "$NC_ROOT"; then
  echo "ERROR: $NC_ROOT/config/config.php missing (Docker layout?)" >&2
  echo "Run: bash scripts/release/fix-nc-config-link.sh" >&2
  echo "Or:  ln -s /opt/nextcloud/config /opt/nextcloud/html/config" >&2
  exit 1
fi

APP_DIR="$NC_ROOT/custom_apps/sharegate"
[[ -d "$APP_DIR" ]] || APP_DIR="$NC_ROOT/apps/sharegate"
if [[ ! -d "$APP_DIR" ]]; then
  echo "ShareGate not at $NC_ROOT/custom_apps/sharegate — run deploy-to-nc.sh first" >&2
  exit 1
fi

step "F2 deploy verify"
echo "NC_BASE: ${NC_BASE:-}"
echo "NC_ROOT: $NC_ROOT  (occ here)"
echo "NC_CONFIG: ${NC_CONFIG:-<default>}"
echo "APP:     $APP_DIR"
[[ -n "${NC_URL:-}" ]] && echo "NC_URL:  $NC_URL"

if [[ -n "${NC_URL:-}" ]]; then
  step "Check NC reachable"
  curl -sf "$NC_URL/status.php" >/dev/null || { echo "Cannot reach $NC_URL/status.php" >&2; exit 1; }
fi

step "occ status"
occ status

step "composer install --no-dev (in app dir)"
if command -v composer >/dev/null 2>&1; then
  (cd "$APP_DIR" && composer install --no-dev --no-interaction --optimize-autoloader)
elif [[ -f "$APP_ROOT/composer.phar" ]]; then
  (cd "$APP_DIR" && $NC_PHP "$APP_ROOT/composer.phar" install --no-dev --no-interaction --optimize-autoloader)
else
  echo "WARN: composer not found, skipping"
fi

step "occ app:enable sharegate"
occ app:enable sharegate

step "occ upgrade"
occ upgrade --no-interaction

step "occ app:list (sharegate)"
occ app:list --enabled | grep -i sharegate

step "Check migrations (optional)"
STATUS=""
for cmd in "migrations:status sharegate" "migration:status sharegate"; do
  if STATUS="$(occ $cmd 2>&1)" && echo "$STATUS" | grep -qE '000001|000002|Executed|executed'; then
    echo "$STATUS"
    break
  fi
done
if [[ -z "$STATUS" ]] || ! echo "$STATUS" | grep -q 000002Date20250603000000; then
  echo "WARN: migration:status unavailable or 002 not listed; verifying tables instead"
fi

step "Verify sharegate tables"
VERIFIED=0
if [[ -n "${NC_CONFIG:-}" && -f "$NC_CONFIG" ]] && command -v mysql >/dev/null 2>&1; then
  read_db() {
    $NC_PHP -r "
      include '$NC_CONFIG';
      echo (\$CONFIG['$1'] ?? '');
    "
  }
  DBHOST="$(read_db dbhost)"
  DBNAME="$(read_db dbname)"
  DBUSER="$(read_db dbuser)"
  DBPASS="$(read_db dbpassword)"
  HOST="${DBHOST%%:*}"
  PORT="${DBHOST##*:}"
  [[ "$PORT" == "$HOST" ]] && PORT=3306
  for t in sharegate_shares sharegate_payments sharegate_access_grants sharegate_share_stats; do
    mysql -h "$HOST" -P "$PORT" -u "$DBUSER" -p"$DBPASS" "$DBNAME" -N -e "SHOW TABLES LIKE '$t';" | grep -q "$t" \
      || { echo "Missing table: $t" >&2; exit 1; }
    echo "OK: $t"
  done
  VERIFIED=1
fi

if [[ "$VERIFIED" -eq 0 ]]; then
  echo "Tip: install mysql client or confirm tables in DB UI"
  echo "Required: sharegate_shares, sharegate_payments, sharegate_access_grants, sharegate_share_stats"
fi

echo ""
echo "[F2] PASS"
echo "ShareGate: ${NC_URL:-}/index.php/apps/sharegate/"
echo "Next: bash scripts/release/f3-e2e-mock.sh"
