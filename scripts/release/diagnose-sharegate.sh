#!/usr/bin/env bash
# Quick ShareGate deploy health check (run on the Nextcloud server as root or admin).
set -euo pipefail

APP_ID=sharegate
NC_ROOT="${NC_ROOT:-/var/www/nextcloud}"

if [[ -f "$NC_ROOT/occ" ]]; then
  OCC=(php "$NC_ROOT/occ")
elif [[ -f /var/www/nextcloud/occ ]]; then
  NC_ROOT=/var/www/nextcloud
  OCC=(php "$NC_ROOT/occ")
else
  echo "Set NC_ROOT to your Nextcloud install (directory containing occ)." >&2
  exit 1
fi

for dir in "$NC_ROOT/custom_apps/$APP_ID" "$NC_ROOT/apps/$APP_ID"; do
  if [[ -d "$dir/appinfo" ]]; then
    APP_DIR="$dir"
    break
  fi
done

if [[ -z "${APP_DIR:-}" ]]; then
  echo "ERROR: $APP_ID not found under custom_apps/ or apps/" >&2
  exit 1
fi

echo "App dir: $APP_DIR"
grep -E '<version>' "$APP_DIR/appinfo/info.xml" | head -1 || true

REQUIRED=(
  "$APP_DIR/lib/Util/ShareFileResolver.php"
  "$APP_DIR/lib/Migration/Version000004Date20250603120000.php"
  "$APP_DIR/lib/Payment/StripeProvider.php"
  "$APP_DIR/lib/Payment/PayPalProvider.php"
  "$APP_DIR/vendor/autoload.php"
  "$APP_DIR/js/dashboard.js"
)

missing=0
for f in "${REQUIRED[@]}"; do
  if [[ ! -f "$f" ]]; then
    echo "MISSING: $f"
    missing=1
  fi
done
if [[ "$missing" -eq 0 ]]; then
  echo "Required files: OK"
fi

echo ""
echo "PHP syntax (lib):"
find "$APP_DIR/lib" -name '*.php' -print0 | while IFS= read -r -d '' f; do
  php -l "$f" >/dev/null || { echo "SYNTAX ERROR: $f"; exit 1; }
done
echo "PHP syntax: OK"

echo ""
echo "App status:"
"${OCC[@]}" app:list | grep -i "$APP_ID" || true

echo ""
echo "DB column file_id:"
"${OCC[@]}" db:query "SHOW COLUMNS FROM oc_sharegate_shares LIKE 'file_id'" 2>/dev/null \
  || "${OCC[@]}" db:query "SELECT column_name FROM information_schema.columns WHERE table_name='oc_sharegate_shares' AND column_name='file_id'" 2>/dev/null \
  || echo "(Could not query — run: occ upgrade)"

echo ""
echo "Recent ShareGate log lines:"
LOG="$NC_ROOT/data/nextcloud.log"
if [[ -f "$LOG" ]]; then
  grep -i sharegate "$LOG" | tail -15 || echo "(no sharegate lines in tail)"
else
  echo "Log not found: $LOG"
fi

echo ""
echo "If file_id column is missing, run:"
echo "  cd $NC_ROOT && sudo -u www-data php occ upgrade"
echo "If app is broken, recover with:"
echo "  cd $NC_ROOT && sudo -u www-data php occ app:disable $APP_ID"
