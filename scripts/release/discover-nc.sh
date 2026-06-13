#!/usr/bin/env bash
# List Nextcloud instances on this host (occ + URL hint)
set -euo pipefail

echo "=== Nextcloud instances on this host ==="
echo ""

candidates=()
while IFS= read -r -d '' occ; do
  candidates+=("$(dirname "$occ")")
done < <(find /opt /var/www -maxdepth 5 -name occ -type f 2>/dev/null | sort -u | tr '\n' '\0')

if [[ ${#candidates[@]} -eq 0 ]]; then
  echo "No occ found under /opt or /var/www"
  exit 1
fi

i=1
for root in "${candidates[@]}"; do
  echo "--- Instance #$i ---"
  echo "NC_ROOT: $root"
  if [[ -f "$root/config/config.php" ]]; then
    SZ=$(wc -c <"$root/config/config.php" | tr -d ' ')
    echo "config.php: $root/config/config.php ($SZ bytes)"
    php -r "
      include '$root/config/config.php';
      echo 'datadirectory: ' . (\$CONFIG['datadirectory'] ?? '?') . PHP_EOL;
      echo 'dbtype: ' . (\$CONFIG['dbtype'] ?? '?') . PHP_EOL;
    " 2>/dev/null || true
  elif [[ -L "$root/config" ]]; then
    echo "config: symlink -> $(readlink -f "$root/config")"
  else
    echo "config: MISSING or broken"
  fi
  if [[ -d "$root/custom_apps/sharegate" ]]; then
    echo "ShareGate: YES -> $root/custom_apps/sharegate"
    grep -oP 'max-version="\K[^"]+' "$root/custom_apps/sharegate/appinfo/info.xml" 2>/dev/null \
      | xargs -I{} echo "  max-version: {}" || true
  else
    echo "ShareGate: not installed"
  fi
  echo ""
  i=$((i + 1))
done

echo "=== URL mapping (manual) ==="
echo "  :8080        -> usually Docker NC under /opt/nextcloud/html"
echo "  /nextcloud   -> usually DietPi NC under /var/www/..."
echo ""
echo "Pick ONE instance, set NC_ROOT + NC_URL in env.local.sh"
