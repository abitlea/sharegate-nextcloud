#!/usr/bin/env bash
# Recover DietPi Nextcloud when Web UI shows 500 / log path unknown
set -euo pipefail

NC_ROOT="${NC_ROOT:-/var/www/nextcloud}"
NC_PHP="${NC_PHP:-php}"
NC_OCC_USER="${NC_OCC_USER:-www-data}"
APP_ID=sharegate

step() { echo ""; echo "==> $*"; }

if [[ ! -f "$NC_ROOT/occ" ]]; then
  echo "occ not found at $NC_ROOT/occ — set NC_ROOT" >&2
  exit 1
fi

CFG="$NC_ROOT/config/config.php"
[[ -f "$CFG" ]] || { echo "missing $CFG" >&2; exit 1; }

step "config.php"
$NC_PHP -r "
  include '$CFG';
  echo 'installed: ' . ((\$CONFIG['installed'] ?? false) ? 'true' : 'false') . PHP_EOL;
  echo 'datadirectory: ' . (\$CONFIG['datadirectory'] ?? '(unset)') . PHP_EOL;
  echo 'version: ' . (\$CONFIG['version'] ?? '?') . PHP_EOL;
  if (!empty(\$CONFIG['logfile'])) echo 'logfile: ' . \$CONFIG['logfile'] . PHP_EOL;
"

DATADIR="$($NC_PHP -r "include '$CFG'; echo \$CONFIG['datadirectory'] ?? '';")"
LOGFILE="$($NC_PHP -r "include '$CFG'; echo \$CONFIG['logfile'] ?? '';")"

step "Find Nextcloud / PHP logs"
CANDIDATES=()
[[ -n "$LOGFILE" ]] && CANDIDATES+=("$LOGFILE")
[[ -n "$DATADIR" ]] && CANDIDATES+=("$DATADIR/nextcloud.log")
CANDIDATES+=(
  "$NC_ROOT/data/nextcloud.log"
  /var/log/apache2/error.log
  /var/log/apache2/nextcloud_error.log
  /var/log/nginx/error.log
  /var/log/lighttpd/error.log
  /var/log/php*-fpm.log
)

FOUND=0
for p in "${CANDIDATES[@]}"; do
  for f in $p; do
    [[ -f "$f" ]] || continue
    echo "--- tail $f ---"
    tail -40 "$f"
    FOUND=1
  done
done
if [[ "$FOUND" -eq 0 ]]; then
  echo "No log file found in common paths."
  echo "Try: journalctl -u apache2 -n 50 --no-pager"
  echo "Try: journalctl -u nginx -n 50 --no-pager"
  journalctl -u apache2 -n 20 --no-pager 2>/dev/null || true
  journalctl -u nginx -n 20 --no-pager 2>/dev/null || true
fi

step "occ status"
(cd "$NC_ROOT" && sudo -u "$NC_OCC_USER" $NC_PHP occ status) || true

step "PHP lint ShareGate (BOM / syntax)"
APP_DIR="$NC_ROOT/apps/$APP_ID"
[[ -d "$APP_DIR" ]] || APP_DIR="$NC_ROOT/custom_apps/$APP_ID"
if [[ -d "$APP_DIR" ]]; then
  while IFS= read -r f; do
    head -c3 "$f" | od -An -tx1 | grep -q 'ef bb bf' && echo "BOM: $f"
    out="$(sudo -u "$NC_OCC_USER" $NC_PHP -l "$f" 2>&1)" || echo "$out"
  done < <(find "$APP_DIR" -name '*.php' ! -path '*/vendor/*' | head -40)
else
  echo "ShareGate app dir not found"
fi

step "Disable ShareGate (restores NC if app is the cause)"
echo "Run manually if needed:"
echo "  cd $NC_ROOT && sudo -u $NC_OCC_USER $NC_PHP occ app:disable $APP_ID"

if [[ "${DISABLE_SHAREGATE:-}" == "1" ]]; then
  (cd "$NC_ROOT" && sudo -u "$NC_OCC_USER" $NC_PHP occ app:disable "$APP_ID") || true
  echo "ShareGate disabled. Retry: http://192.168.128.128/nextcloud/"
fi

step "done"
