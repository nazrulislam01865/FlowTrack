#!/usr/bin/env bash
set -euo pipefail

if [ ! -d vendor ]; then
  echo "vendor/ is missing. Run: composer install" >&2
  exit 1
fi

npm run build
php artisan optimize:clear
php artisan route:list >/tmp/flowtrack-routes.txt
php artisan test

echo "FlowTrack verification passed."
echo "Routes written to /tmp/flowtrack-routes.txt"
