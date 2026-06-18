#!/usr/bin/env bash
# Diagnose Nextcloud language / locale locks (run on the NC server as root or with sudo).
# Usage:
#   NC_ROOT=/var/www/nextcloud bash scripts/release/check-nc-language.sh
#   NC_ROOT=/var/www/nextcloud NC_USER=admin bash scripts/release/check-nc-language.sh zh_CN
set -euo pipefail

NC_ROOT="${NC_ROOT:-/var/www/nextcloud}"
NC_OCC_USER="${NC_OCC_USER:-www-data}"
NC_USER="${NC_USER:-admin}"
TARGET_LANG="${1:-}"

if [[ ! -f "${NC_ROOT}/occ" ]]; then
  echo "ERROR: occ not found under NC_ROOT=${NC_ROOT}" >&2
  exit 1
fi

occ() {
  sudo -u "${NC_OCC_USER}" php "${NC_ROOT}/occ" "$@"
}

echo "==> Nextcloud: $(occ -V 2>/dev/null | head -1 || true)"
echo "==> NC_ROOT: ${NC_ROOT}"
echo ""

echo "==> System language locks (empty = users can change language in Personal settings)"
for key in force_language force_locale default_language default_locale; do
  val="$(occ config:system:get "${key}" 2>/dev/null || true)"
  if [[ -n "${val}" ]]; then
    echo "  ${key} = ${val}"
  else
    echo "  ${key} = (not set)"
  fi
done
echo ""

echo "==> User '${NC_USER}' personal language / locale"
for key in lang locale; do
  val="$(occ user:setting "${NC_USER}" settings "${key}" 2>/dev/null || true)"
  if [[ -n "${val}" ]]; then
    echo "  ${key} = ${val}"
  else
    echo "  ${key} = (not set — NC may use browser or default_language)"
  fi
done
echo ""

if [[ -n "${TARGET_LANG}" ]]; then
  echo "==> Setting user '${NC_USER}' language to ${TARGET_LANG}"
  occ user:setting "${NC_USER}" settings lang "${TARGET_LANG}"
  echo "Done. Log out of Nextcloud and hard-refresh (Ctrl+F5), or use a private window."
  echo ""
fi

force="$(occ config:system:get force_language 2>/dev/null || true)"
if [[ -n "${force}" ]]; then
  echo "NOTE: force_language is set — the Personal settings language dropdown is disabled."
  echo "To allow per-user language, on the server run:"
  echo "  sudo -u ${NC_OCC_USER} php ${NC_ROOT}/occ config:system:delete force_language"
  echo "  sudo -u ${NC_OCC_USER} php ${NC_ROOT}/occ config:system:delete force_locale"
fi

sharegate_dir=""
for d in "${NC_ROOT}/custom_apps/sharegate" "${NC_ROOT}/apps/sharegate"; do
  if [[ -f "${d}/appinfo/info.xml" ]]; then
    sharegate_dir="${d}"
    break
  fi
done
if [[ -n "${sharegate_dir}" ]]; then
  ver="$(grep -oP '(?<=<version>)[^<]+' "${sharegate_dir}/appinfo/info.xml" 2>/dev/null || true)"
  echo ""
  echo "==> ShareGate at ${sharegate_dir} version ${ver:-unknown}"
  if [[ -f "${sharegate_dir}/l10n/zh_CN.json" && -f "${sharegate_dir}/l10n/en.json" ]]; then
    echo "  l10n JSON: en.json + zh_CN.json present"
  else
    echo "  l10n JSON: missing en.json or zh_CN.json — update ShareGate before i18n testing"
  fi
  if [[ -f "${sharegate_dir}/l10n/zh_CN.js" && -f "${sharegate_dir}/l10n/en.js" ]]; then
    echo "  l10n JS: en.js + zh_CN.js present (required for Vue dashboard)"
  else
    echo "  l10n JS: MISSING — run: sudo -u www-data php ${NC_ROOT}/occ l10n:createjs sharegate"
  fi
fi
