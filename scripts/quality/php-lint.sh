#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

status=0
count=0
while IFS= read -r -d '' file; do
  count=$((count + 1))
  if ! php -l "$file" >/dev/null; then
    php -l "$file" || true
    status=1
  fi
done < <(find app bootstrap config database routes tests -type f -name '*.php' -print0 2>/dev/null)

if [ "$status" -ne 0 ]; then
  echo "PHP lint failed."
  exit "$status"
fi

echo "PHP lint passed for $count files."
