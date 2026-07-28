#!/usr/bin/env bash
set -euo pipefail

rm -rf public/build public/hot
npm install
npm run build
php artisan optimize:clear

echo "Frontend build completed. Manifest: public/build/manifest.json"
