#!/usr/bin/env bash
set -euo pipefail

# Vite prefers vite.config.js when both JS and TS configs exist. The old JS
# config referenced deleted starter-kit assets, so it must not remain.
rm -f vite.config.js

for entry in \
    resources/css/flowtrack-build.css \
    resources/js/flowtrack-build.ts
do
    if [[ ! -f "$entry" ]]; then
        echo "Missing Vite entry: $entry" >&2
        exit 1
    fi
done

rm -rf public/build public/hot
npm install
npm run build
php artisan optimize:clear

echo "Frontend build completed. Manifest: public/build/manifest.json"
