#!/usr/bin/env bash
set -euo pipefail

mkdir -p \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/testing \
  storage/framework/views \
  storage/logs \
  bootstrap/cache

chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true

php artisan optimize:clear

echo "FlowTrack runtime directories are ready."
