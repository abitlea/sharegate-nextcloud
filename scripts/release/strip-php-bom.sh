#!/usr/bin/env bash
# Remove UTF-8 BOM from PHP files (breaks declare(strict_types=1) on Linux PHP)
set -euo pipefail

ROOT="${1:-.}"

strip_one() {
  local f="$1"
  local first
  first="$(head -c 3 "$f" | od -An -tx1 | tr -d ' \n')"
  if [[ "$first" == "efbbbf" ]]; then
    tail -c +4 "$f" >"${f}.tmp" && mv "${f}.tmp" "$f"
    echo "stripped BOM: $f"
  fi
}

while IFS= read -r -d '' f; do
  strip_one "$f"
done < <(find "$ROOT" -name '*.php' ! -path '*/vendor/*' -print0)

echo "BOM scan done under $ROOT"
