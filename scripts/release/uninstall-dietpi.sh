#!/usr/bin/env bash
# Remove manually deployed ShareGate (deploy-dietpi.sh) so App Store install can proceed.
# Typical issue: app copied to apps/sharegate (writable=false) — NC UI "Uninstall" cannot delete files.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
NC_URL="${NC_URL:-http://covevault.top/nextcloud}"
NC_URL="${NC_URL%/}"
NC_OCC_USER="${NC_OCC_USER:-www-data}"
NC_PHP="${NC_PHP:-php}"
APP_ID=sharegate
KEEP_DATA="${KEEP_DATA:-1}"

step() { echo ""; echo "==> $*"; }

find_nc_root() {
  if [[ -n "${NC_ROOT:-}" && -f "${NC_ROOT}/occ" ]]; then
    echo "$NC_ROOT"
    return 0
  fi
  local found=""
  while IFS= read -r occ; do
    local root
    root="$(dirname "$occ")"
    [[ "$root" == *"/opt/nextcloud/html"* ]] && continue
    [[ -z "$found" ]] && found="$root"
  done < <(find /var/www -maxdepth 5 -name occ -type f 2>/dev/null)
  [[ -n "$found" ]] && echo "$found" && return 0
  return 1
}

occ() {
  (cd "$NC_ROOT" && sudo -u "$NC_OCC_USER" $NC_PHP occ "$@")
}

step "Resolve NC_ROOT"
if ! NC_ROOT="$(find_nc_root)"; then
  echo "Set NC_ROOT=/var/www/nextcloud (or your occ directory)" >&2
  exit 1
fi
export NC_ROOT
echo "NC_ROOT: $NC_ROOT"
echo "NC_URL:  $NC_URL"

APPS_DIR="$NC_ROOT/apps/$APP_ID"
CUSTOM_DIR="$NC_ROOT/custom_apps/$APP_ID"

step "Current app paths"
for d in "$APPS_DIR" "$CUSTOM_DIR"; do
  if [[ -d "$d" ]]; then
    echo "  FOUND: $d"
    grep -E '<version>|<id>' "$d/appinfo/info.xml" 2>/dev/null | head -2 || true
  else
    echo "  absent: $d"
  fi
done

step "Disable $APP_ID"
occ app:disable "$APP_ID" 2>/dev/null || echo "  (already disabled or not registered)"

step "occ app:remove (may fail for apps/ copy — that is OK)"
if [[ "$KEEP_DATA" == "1" ]]; then
  occ app:remove "$APP_ID" --keep-data 2>/dev/null || true
else
  occ app:remove "$APP_ID" 2>/dev/null || true
fi

step "Remove app directories (both apps/ and custom_apps/)"
for d in "$APPS_DIR" "$CUSTOM_DIR"; do
  if [[ -d "$d" ]]; then
    echo "  rm -rf $d"
    rm -rf "$d"
  fi
done

step "Refresh app registry"
occ app:list 2>/dev/null | grep -i "$APP_ID" && {
  echo "WARN: sharegate still listed — run: cd $NC_ROOT && sudo -u $NC_OCC_USER $NC_PHP occ maintenance:repair"
} || echo "OK: sharegate not in app:list"

step "Done"
echo ""
echo "Next steps:"
echo "  1. In NC Web UI: 应用 → search ShareGate → Install from App Store"
echo "  2. Or: cd $NC_ROOT && sudo -u $NC_OCC_USER $NC_PHP occ app:install $APP_ID"
echo "  3. Open: $NC_URL/index.php/apps/sharegate/"
if [[ "$KEEP_DATA" == "1" ]]; then
  echo ""
  echo "Database tables sharegate_* were kept (KEEP_DATA=1)."
  echo "Fresh DB: KEEP_DATA=0 bash $0"
fi
