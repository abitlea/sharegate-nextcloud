#!/usr/bin/env bash
# Run on the Nextcloud server (DietPi). Finds ShareGate 500 root cause.
set -euo pipefail

NC_ROOT="${NC_ROOT:-/var/www/nextcloud}"
APP="$NC_ROOT/apps/sharegate"
LOG="$NC_ROOT/data/nextcloud.log"
REQ_ID="${1:-}"

echo "=== ShareGate deploy smoke test ==="

if [[ ! -d "$APP" ]]; then
  echo "ERROR: $APP not found" >&2
  exit 1
fi

echo ""
echo "-- Required files --"
for f in \
  lib/Util/ShareFileResolver.php \
  lib/Payment/StripeProvider.php \
  lib/Payment/PayPalProvider.php \
  lib/Payment/PaymentProviderCatalog.php \
  lib/Migration/Version000004Date20250603120000.php \
  vendor/autoload.php \
  js/dashboard.js
do
  if [[ -f "$APP/$f" ]]; then
    echo "OK  $f"
  else
    echo "MISSING  $f"
  fi
done

echo ""
echo "-- ParameterType in migration (must be empty) --"
grep -n ParameterType "$APP/lib/Migration/Version000004Date20250603120000.php" 2>/dev/null || echo "(none — good)"

echo ""
echo "-- App status --"
php "$NC_ROOT/occ" app:list --enabled 2>/dev/null | grep -i sharegate || echo "sharegate NOT in enabled list"
php "$NC_ROOT/occ" app:list 2>/dev/null | grep -i sharegate || true

echo ""
echo "-- PHP class autoload --"
php -r "
require '$APP/vendor/autoload.php';
\$classes = [
  'OCA\\\\ShareGate\\\\Util\\\\ShareFileResolver',
  'OCA\\\\ShareGate\\\\Payment\\\\StripeProvider',
  'OCA\\\\ShareGate\\\\Payment\\\\PayPalProvider',
  'OCA\\\\ShareGate\\\\Service\\\\DashboardService',
];
foreach (\$classes as \$c) {
  echo (class_exists(\$c) ? 'OK  ' : 'FAIL ') . \$c . PHP_EOL;
}
"

echo ""
echo "-- NC container (ShareFileResolver) --"
cd "$NC_ROOT"
sudo -u www-data php <<'PHP'
<?php
require __DIR__ . '/lib/base.php';
\OC::$CLI = true;
try {
	\OC::init();
	$appManager = \OC::$server->get(\OCP\App\IAppManager::class);
	if (!$appManager->isEnabledForAnyone('sharegate')) {
		echo "WARN: sharegate is not enabled\n";
	}
	$r = \OC::$server->get(\OCA\ShareGate\Util\ShareFileResolver::class);
	echo "OK  ShareFileResolver from container\n";
} catch (Throwable $e) {
	echo "FAIL " . $e->getMessage() . "\n";
	echo $e->getTraceAsString() . "\n";
	exit(1);
}
PHP

echo ""
echo "-- Recent log (sharegate / Error) --"
if [[ -f "$LOG" ]]; then
  if [[ -n "$REQ_ID" ]]; then
    grep "$REQ_ID" "$LOG" || echo "(no lines for Request ID $REQ_ID)"
  fi
  grep -iE 'sharegate|ShareFileResolver|StripeProvider|file_id|Fatal|Error' "$LOG" | tail -25 || true
else
  echo "Log not found: $LOG"
fi

echo ""
echo "Done."
