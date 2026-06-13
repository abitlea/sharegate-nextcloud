#!/usr/bin/env bash
# Deploy ShareGate to custom_apps
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=nc-resolve.sh
source "$SCRIPT_DIR/nc-resolve.sh"

SRC="${1:-$(cd "$SCRIPT_DIR/../.." && pwd)}"
DEST="$NC_ROOT/custom_apps/sharegate"

if [[ ! -f "$NC_ROOT/occ" ]]; then
  echo "occ not found under NC_ROOT=$NC_ROOT" >&2
  echo "For Docker layout set: export NC_ROOT=/opt/nextcloud/html" >&2
  exit 1
fi

echo "Deploy $SRC -> $DEST"
mkdir -p "$(dirname "$DEST")"
rsync -a --delete \
  --exclude '.git' \
  --exclude 'release' \
  --exclude 'docker' \
  --exclude 'tests' \
  --exclude 'node_modules' \
  --exclude '.phpunit.cache' \
  --exclude 'vendor/bin/phpunit' \
  "$SRC/" "$DEST/"

echo ""
echo "Done."
echo "  cd $DEST && composer install --no-dev"
echo "  cd $NC_ROOT && sudo -u www-data php occ app:enable sharegate"
echo "  cd $NC_ROOT && sudo -u www-data php occ upgrade"
