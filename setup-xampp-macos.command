#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")"

mkdir -p storage/framework/{cache/data,sessions,testing,views} storage/app/flowtrack-documents storage/logs bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true

if [[ ! -f .env ]]; then
    cp .env.example .env
fi

if [[ ! -d vendor ]]; then
    command -v composer >/dev/null 2>&1 || { echo "Composer is required." >&2; exit 1; }
    composer install --no-interaction
fi

if ! grep -Eq '^APP_KEY=base64:.+' .env; then
    php artisan key:generate
fi
if php scripts/check-vite-build.php public/build >/dev/null 2>&1; then
    echo "Using the verified bundled frontend build."
else
    ./scripts/frontend-install-and-build.sh
fi
php artisan optimize:clear
php artisan storage:link >/dev/null 2>&1 || true
php artisan migrate --force
php scripts/check-vite-build.php public/build

echo
printf '%s\n' "FlowTrack is ready. Start it with: php artisan serve"
