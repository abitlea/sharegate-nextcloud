#!/usr/bin/env bash
# Run ON DietPi after copying sharegate files — quick NC 33 sanity check.
set -euo pipefail

APP_DIR="${1:-/var/www/nextcloud/apps/sharegate}"

echo "Checking: $APP_DIR"

fail() { echo "FAIL: $*" >&2; exit 1; }
ok() { echo "OK: $*"; }

[[ -d "$APP_DIR" ]] || fail "directory missing"

if [[ -f "$APP_DIR/appinfo/app.php" ]]; then
  fail "appinfo/app.php exists — run: rm -f $APP_DIR/appinfo/app.php"
fi
ok "no appinfo/app.php"

if grep -q 'function update(Payment' "$APP_DIR/lib/Db/PaymentMapper.php" 2>/dev/null; then
  fail "PaymentMapper still has update(Payment) override — redeploy lib/Db/PaymentMapper.php"
fi
ok "PaymentMapper has no typed update() override"

for f in "$APP_DIR"/lib/Db/{Payment,Share,AccessGrant}.php; do
  [[ -f "$f" ]] || continue
  if grep -qE '\$(public|protected) \?int \$id' "$f"; then
    fail "$(basename "$f") still declares typed \$id"
  fi
done
ok "Entity \$id not redeclared"

php -l "$APP_DIR/lib/Db/PaymentMapper.php" >/dev/null
php -l "$APP_DIR/lib/AppInfo/Application.php" >/dev/null
ok "PHP syntax"

echo ""
echo "All checks passed. Restart PHP-FPM/Apache, then hard-refresh the dashboard."
echo "  systemctl restart php8.2-fpm  # or apache2"
