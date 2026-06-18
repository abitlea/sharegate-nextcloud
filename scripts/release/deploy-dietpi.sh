#!/usr/bin/env bash
# Deploy ShareGate to DietPi Nextcloud (http://host/nextcloud)
# Self-fix CRLF when copied from Windows (re-exec once after sed; do not use grep here)
if [ -z "${SHAREGATE_CRLF_FIXED:-}" ] && [ -n "${BASH_SOURCE[0]:-}" ] && [ -f "${BASH_SOURCE[0]}" ]; then
  _self="${BASH_SOURCE[0]}"
  if command -v sed >/dev/null 2>&1; then
    sed -i 's/\r$//' "$_self" 2>/dev/null || true
  elif command -v perl >/dev/null 2>&1; then
    perl -pi -e 's/\r$//' "$_self"
  fi
  export SHAREGATE_CRLF_FIXED=1
  exec env SHAREGATE_CRLF_FIXED=1 bash "$_self" "$@"
fi
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
for _sh in "$SCRIPT_DIR"/*.sh; do
  if [ -f "$_sh" ] && command -v sed >/dev/null 2>&1; then
    sed -i 's/\r$//' "$_sh" 2>/dev/null || true
  fi
done
unset _self
SRC="${1:-$(cd "$SCRIPT_DIR/../.." && pwd)}"

NC_URL="${NC_URL:-http://192.168.128.128/nextcloud}"
NC_URL="${NC_URL%/}"
NC_OCC_USER="${NC_OCC_USER:-www-data}"
NC_PHP="${NC_PHP:-php}"
APP_ID=sharegate

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

deploy_tree() {
  local dest="$1"
  mkdir -p "$(dirname "$dest")"
  rsync -a --delete \
    --exclude '.git' \
    --exclude 'release' \
    --exclude 'docker' \
    --exclude 'tests' \
    --exclude 'node_modules' \
    --exclude '.phpunit.cache' \
    "$SRC/" "$dest/"
}

strip_php_bom() {
  local dir="$1"
  while IFS= read -r -d '' f; do
    local first
    first="$(head -c 3 "$f" | od -An -tx1 | tr -d ' \n')"
    if [[ "$first" == "efbbbf" ]]; then
      tail -c +4 "$f" >"${f}.tmp" && mv "${f}.tmp" "$f"
      echo "stripped BOM: $f"
    fi
  done < <(find "$dir" -name '*.php' ! -path '*/vendor/*' -print0)
}

occ() {
  (cd "$NC_ROOT" && sudo -u "$NC_OCC_USER" $NC_PHP occ "$@")
}

app_is_visible() {
  occ app:list 2>/dev/null | grep -qi "$APP_ID"
}

ensure_custom_apps_path() {
  local cfg="$NC_ROOT/config/config.php"
  $NC_PHP -r "
    include '$cfg';
    if (!empty(\$CONFIG['apps_paths'])) {
      foreach (\$CONFIG['apps_paths'] as \$p) {
        if (str_contains(\$p['path'] ?? '', 'custom_apps')) exit(0);
      }
    }
    exit(1);
  " 2>/dev/null && return 0

  step "WARN: config.php has no custom_apps in apps_paths"
  echo "Add to config.php (before closing ); ):"
  echo ""
  cat <<'PHP'
  'apps_paths' =>
  array (
    0 =>
    array (
      'path' => '/var/www/nextcloud/apps',
      'url' => '/apps',
      'writable' => false,
    ),
    1 =>
    array (
      'path' => '/var/www/nextcloud/custom_apps',
      'url' => '/custom_apps',
      'writable' => true,
    ),
  ),
PHP
  echo ""
  echo "Or deploy to apps/sharegate (see fallback below)."
}

step "Resolve DietPi NC_ROOT"
if ! NC_ROOT="$(find_nc_root)"; then
  echo "Set NC_ROOT=/var/www/nextcloud" >&2
  exit 1
fi
export NC_ROOT
echo "NC_ROOT: $NC_ROOT"
echo "NC_URL:  $NC_URL"

[[ -f "$NC_ROOT/occ" ]] || { echo "occ missing" >&2; exit 1; }

step "Nextcloud version"
occ status

ensure_custom_apps_path || true

# DietPi 常见：config 未注册 custom_apps，应用必须放在 apps/ 才会被扫描
USE_APPS_DIR=0
if ! $NC_PHP -r "
  include '$NC_ROOT/config/config.php';
  if (empty(\$CONFIG['apps_paths'])) exit(1);
  foreach (\$CONFIG['apps_paths'] as \$p) {
    if (str_contains(\$p['path'] ?? '', 'custom_apps')) exit(0);
  }
  exit(1);
" 2>/dev/null; then
  USE_APPS_DIR=1
  echo "custom_apps not in apps_paths -> using apps/$APP_ID"
fi

if [[ "$USE_APPS_DIR" -eq 1 ]]; then
  DEST="$NC_ROOT/apps/$APP_ID"
else
  DEST="$NC_ROOT/custom_apps/$APP_ID"
fi
step "Deploy -> $DEST"
deploy_tree "$DEST"
strip_php_bom "$DEST"
# NC 33: legacy app.php must not exist (use lib/AppInfo/Application.php IBootstrap)
rm -f "$DEST/appinfo/app.php"
chown -R "$NC_OCC_USER:$NC_OCC_USER" "$(dirname "$DEST")"

step "composer install --no-dev"
if command -v composer >/dev/null 2>&1; then
  (cd "$DEST" && composer install --no-dev --no-interaction --optimize-autoloader)
elif [[ -f "$SRC/composer.phar" ]]; then
  (cd "$DEST" && $NC_PHP "$SRC/composer.phar" install --no-dev --no-interaction --optimize-autoloader)
fi

step "Validate l10n JSON (translations wrapper)"
$NC_PHP -r "
  foreach (glob('$DEST/l10n/*.json') ?: [] as \$f) {
    \$j = json_decode((string)file_get_contents(\$f), true);
    if (!is_array(\$j) || !isset(\$j['translations']) || !is_array(\$j['translations'])) {
      fwrite(STDERR, \"Invalid l10n (need translations key): \$f\n\");
      exit(1);
    }
  }
  echo \"l10n JSON OK\n\";
"

step "Generate l10n JS for browser (Util::addTranslations loads *.js, not *.json)"
if occ l10n:createjs "$APP_ID" 2>/dev/null; then
  echo "occ l10n:createjs OK"
else
  $NC_PHP -r "
    \$appId = 'sharegate';
    foreach (glob('$DEST/l10n/*.json') ?: [] as \$jsonFile) {
      \$lang = basename(\$jsonFile, '.json');
      \$bundle = json_decode((string)file_get_contents(\$jsonFile), true);
      if (!is_array(\$bundle['translations'] ?? null)) continue;
      \$lines = [\"OC.L10N.register(\", \"\\t\" . json_encode(\$appId) . \",\" , \"\\t{\"];
      \$entries = array_keys(\$bundle['translations']);
      \$last = count(\$entries) - 1;
      foreach (\$entries as \$i => \$key) {
        \$comma = \$i < \$last ? ',' : '';
        \$lines[] = \"\\t\\t\" . json_encode(\$key) . ' : ' . json_encode(\$bundle['translations'][\$key]) . \$comma;
      }
      \$plural = \$bundle['pluralForm'] ?? 'nplurals=2; plural=(n != 1);';
      \$lines[] = \"\\t},\";
      \$lines[] = \"\\t\" . json_encode(\$plural) . \");\";
      \$lines[] = '';
      file_put_contents('$DEST/l10n/' . \$lang . '.js', implode(\"\\n\", \$lines));
    }
    echo \"l10n JS generated\\n\";
  "
fi
for js in "$DEST/l10n/en.js" "$DEST/l10n/zh_CN.js"; do
  if [[ ! -f "$js" ]]; then
    echo "ERROR: missing $js — Vue i18n will stay English" >&2
    exit 1
  fi
done

step "PHP syntax check (ShareGate)"
while IFS= read -r f; do
  sudo -u "$NC_OCC_USER" $NC_PHP -l "$f" >/dev/null || {
    echo "PHP syntax error: $f" >&2
    exit 1
  }
done < <(find "$DEST/lib" "$DEST/appinfo" -name '*.php' 2>/dev/null)

step "NC 33 compatibility checks"
if [[ -f "$DEST/appinfo/app.php" ]]; then
  echo "ERROR: appinfo/app.php still present — remove it for NC 33" >&2
  exit 1
fi
if grep -q 'function update(Payment' "$DEST/lib/Db/PaymentMapper.php" 2>/dev/null; then
  echo "ERROR: PaymentMapper still overrides update() — pull latest lib/Db/PaymentMapper.php" >&2
  exit 1
fi
if grep -qE '\$(public|protected) \?int \$id' "$DEST/lib/Db/"*.php 2>/dev/null; then
  echo "ERROR: Entity classes must not declare typed \$id (NC 33)" >&2
  exit 1
fi
echo "NC 33 checks OK"

step "Permissions + info.xml"
chown -R "$NC_OCC_USER:$NC_OCC_USER" "$DEST" "$(dirname "$DEST")"
sed -i 's/max-version="30"/max-version="33"/' "$DEST/appinfo/info.xml" 2>/dev/null || true
grep -E '<version>|max-version|<id>' "$DEST/appinfo/info.xml" | head -5

step "Can www-data read app?"
sudo -u "$NC_OCC_USER" test -r "$DEST/appinfo/info.xml"

step "occ app:list (look for sharegate)"
if ! app_is_visible; then
  echo "sharegate NOT in app:list from custom_apps — trying apps/$APP_ID"
  APPS_DEST="$NC_ROOT/apps/$APP_ID"
  deploy_tree "$APPS_DEST"
  (cd "$APPS_DEST" && composer install --no-dev --no-interaction 2>/dev/null || true)
  chown -R "$NC_OCC_USER:$NC_OCC_USER" "$APPS_DEST"
  DEST="$APPS_DEST"
  if ! app_is_visible; then
    echo "ERROR: sharegate still not visible. Run:" >&2
    echo "  cd $NC_ROOT && sudo -u www-data php occ app:list" >&2
    echo "  ls -la $NC_ROOT/custom_apps/ $NC_ROOT/apps/$APP_ID" >&2
    exit 1
  fi
  echo "OK: visible after deploy to apps/$APP_ID"
else
  echo "OK: sharegate visible in app:list"
fi

step "occ app:enable $APP_ID"
occ app:enable "$APP_ID"

step "occ upgrade"
occ upgrade --no-interaction

step "Verify enabled"
occ app:list --enabled | grep -i "$APP_ID"

echo ""
echo "[DONE] $NC_URL/index.php/apps/sharegate/"
