#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

# This archive introduces Laravel Reverb. If an older composer.lock is still
# present on the first deployment, update only Reverb and its dependencies once.
# Subsequent deployments return to deterministic composer install.
if ! grep -q '"name": "laravel/reverb"' composer.lock 2>/dev/null; then
    composer update laravel/reverb --with-all-dependencies --no-dev --prefer-dist --no-interaction
fi
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
if [[ -f package-lock.json || -f npm-shrinkwrap.json ]]; then
    npm ci
else
    npm install --no-audit --no-fund
fi
npm run build

# Remove stale framework caches before migrations, then rebuild all production
# caches after the new code and schema are in place.
php artisan optimize:clear
php artisan migrate --force
if [[ ! -L public/storage && ! -e public/storage ]]; then
    php artisan storage:link
fi

# Phase 14 release gate. In horizontal mode this fails the deployment before
# traffic is returned if Redis, MySQL, queue connectivity or shared storage
# is unavailable/misconfigured. The storage sentinel is safe and idempotent.
if php artisan list --raw | grep -q '^flowtrack:infrastructure:check'; then
    php artisan flowtrack:infrastructure:check --prepare-storage
fi

php artisan optimize
php artisan queue:restart

# Reverb is a long-running process. This gracefully terminates existing socket
# workers; Supervisor starts the new process with the freshly deployed code.
if php artisan list --raw | grep -q '^reverb:restart'; then
    php artisan reverb:restart || true
fi

echo "FlowTrack deployment optimization completed."
