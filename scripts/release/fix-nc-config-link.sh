#!/usr/bin/env bash
# Fix Docker-style layout: /opt/nextcloud/{config,data,html} where config is outside html
set -euo pipefail

NC_BASE="${NC_BASE:-/opt/nextcloud}"
NC_ROOT="${NC_ROOT:-$NC_BASE/html}"
SRC_CONFIG="$NC_BASE/config"
DEST_LINK="$NC_ROOT/config"

if [[ ! -f "$SRC_CONFIG/config.php" ]]; then
  echo "Source config not found: $SRC_CONFIG/config.php" >&2
  exit 1
fi

if [[ -f "$DEST_LINK/config.php" ]]; then
  SIZE="$(wc -c <"$DEST_LINK/config.php" | tr -d ' ')"
  if [[ "$SIZE" -gt 100 ]]; then
    echo "Already OK: $DEST_LINK/config.php ($SIZE bytes)"
    exit 0
  fi
  echo "WARN: $DEST_LINK/config.php is empty or too small ($SIZE bytes). Replacing with symlink."
  rm -rf "$DEST_LINK"
fi

if [[ -e "$DEST_LINK" && ! -L "$DEST_LINK" ]]; then
  echo "ERROR: $DEST_LINK exists but is not a symlink. Backup and remove manually." >&2
  exit 1
fi

echo "Link: $DEST_LINK -> $SRC_CONFIG"
ln -sfn "$SRC_CONFIG" "$DEST_LINK"
chown -h www-data:www-data "$DEST_LINK" 2>/dev/null || true

echo "Verify:"
ls -la "$DEST_LINK"
cd "$NC_ROOT" && sudo -u www-data php occ status
echo ""
echo "Try: cd $NC_ROOT && sudo -u www-data php occ app:enable sharegate"
