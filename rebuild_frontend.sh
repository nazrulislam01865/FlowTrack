#!/usr/bin/env bash

set -Eeuo pipefail

cd "$(dirname "$0")"

rm -f vite.config.js

for entry in \
    resources/css/app.css \
    resources/js/app.ts
do
    if [[ ! -f "$entry" ]]; then
        echo "Missing Vite entry: $entry" >&2
        exit 1
    fi
done

rm -rf public/build public/hot

npm ci
npm run build

test -s public/build/manifest.json || {
    echo "Build manifest is missing or empty." >&2
    exit 1
}

php artisan optimize:clear

echo "Frontend build completed: public/build/manifest.json"
