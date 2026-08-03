#!/usr/bin/env bash
set -Eeuo pipefail

cd "$(dirname "$0")"

for required in artisan composer.json package.json resources/js/app.js scripts/build-assets.mjs; do
    [[ -e "$required" ]] || { echo "Missing required project file: $required" >&2; exit 1; }
done

mkdir -p storage/framework/{cache/data,sessions,testing,views} storage/logs bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true
rm -f public/hot

if [[ -f package-lock.json ]] && command -v node >/dev/null 2>&1 && command -v npm >/dev/null 2>&1     && node scripts/check-package-lock.mjs >/dev/null 2>&1; then
    ./scripts/frontend-install-and-build.sh
else
    echo "Using the verified bundled frontend build."
    php scripts/check-asset-build.php public/build
fi

php artisan optimize:clear
php artisan migrate --force
php artisan optimize
php artisan route:list --name=flowtrack.dashboard --name=flowtrack.jobs.show --name=flowtrack.clients.show >/dev/null

echo "FlowTrack update deployed successfully."
