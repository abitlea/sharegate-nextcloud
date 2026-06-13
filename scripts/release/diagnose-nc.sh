#!/usr/bin/env bash
# Diagnose why occ says "Nextcloud is not installed"
set -euo pipefail

NC_BASE="${NC_BASE:-/opt/nextcloud}"
NC_ROOT="${NC_ROOT:-$NC_BASE/html}"

echo "=== Nextcloud occ diagnose ==="
echo "NC_BASE: $NC_BASE"
echo "NC_ROOT: $NC_ROOT"
echo ""

fail() { echo "FAIL: $*" >&2; exit 1; }
ok() { echo "OK: $*"; }

[[ -f "$NC_ROOT/occ" ]] || fail "occ missing at $NC_ROOT/occ"

echo "--- config paths ---"
for p in \
  "$NC_ROOT/config/config.php" \
  "$NC_BASE/config/config.php" \
  "$NC_ROOT/../config/config.php"; do
  if [[ -f "$p" ]]; then
    SZ="$(wc -c <"$p" | tr -d ' ')"
    ok "found config.php: $p ($SZ bytes)"
    [[ "$SZ" -lt 100 ]] && echo "  WARN: config.php too small — likely broken"
    CONFIG="$p"
  else
    echo "  missing: $p"
  fi
done

if [[ -z "${CONFIG:-}" ]]; then
  fail "No config.php found. NC cannot run occ app:* commands."
fi

echo ""
echo "--- html/config ---"
if [[ -L "$NC_ROOT/config" ]]; then
  ok "html/config is symlink -> $(readlink -f "$NC_ROOT/config")"
elif [[ -d "$NC_ROOT/config" ]]; then
  ok "html/config is directory"
else
  echo "WARN: $NC_ROOT/config does not exist"
  if [[ -f "$NC_BASE/config/config.php" ]]; then
    echo "FIX: ln -s $NC_BASE/config $NC_ROOT/config"
  fi
fi

echo ""
echo "--- installed flag ---"
INSTALLED="$(php -r "include '$CONFIG'; echo isset(\$CONFIG['installed']) && \$CONFIG['installed'] ? 'true' : 'false';")"
echo "config installed = $INSTALLED"
if [[ "$INSTALLED" != "true" ]]; then
  echo "WARN: set 'installed' => true in config.php if Web UI already works"
fi

echo ""
echo "--- occ status (from NC_ROOT) ---"
(cd "$NC_ROOT" && sudo -u www-data php occ status 2>&1) || true

echo ""
echo "--- occ app:list (limited if not installed) ---"
(cd "$NC_ROOT" && sudo -u www-data php occ app:list 2>&1) || true

echo ""
echo "=== done ==="
