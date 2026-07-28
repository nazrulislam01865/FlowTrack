#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/FleetManagement}"
PHP_USER="${PHP_USER:-www-data}"
SITE_DOWN=0

bring_site_up() {
  if [[ "$SITE_DOWN" -eq 1 ]]; then
    sudo -u "$PHP_USER" php artisan up >/dev/null 2>&1 || true
  fi
}
trap bring_site_up EXIT

cd "$APP_DIR"

# Prepare dependencies and compiled frontend assets while the current release
# remains available. This keeps the maintenance window limited to database and
# framework cache operations.
COMPOSER_ALLOW_SUPERUSER=1 composer install \
  --no-dev \
  --prefer-dist \
  --optimize-autoloader \
  --no-interaction

if [[ -f package-lock.json ]]; then
  npm ci --no-audit --no-fund
else
  npm install --no-audit --no-fund
fi

# Set FLEET_OFFLINE_BUILD=1 only on servers that cannot reach the configured
# remote font provider. Normal production deployments retain the existing font.
FLEET_OFFLINE_BUILD="${FLEET_OFFLINE_BUILD:-0}" npm run build

sudo -u "$PHP_USER" php artisan down || true
SITE_DOWN=1

sudo -u "$PHP_USER" php artisan migrate --force
sudo -u "$PHP_USER" php artisan fleet:rbac-sync
sudo -u "$PHP_USER" php artisan optimize:clear
sudo -u "$PHP_USER" php artisan optimize

sudo -u "$PHP_USER" php artisan up
SITE_DOWN=0
trap - EXIT

echo "Fleet Management production optimization completed."
