#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/flowtrack}"
PHP_USER="${PHP_USER:-www-data}"
SITE_DOWN=0

bring_site_up() {
  if [[ "$SITE_DOWN" -eq 1 ]]; then
    sudo -u "$PHP_USER" php artisan up >/dev/null 2>&1 || true
  fi
}
trap bring_site_up EXIT

cd "$APP_DIR"

for required in artisan composer.json package.json resources/views/flowtrack-template.blade.php public/flowtrack-dynamic.js; do
  [[ -e "$required" ]] || { echo "Missing required FlowTrack file: $required" >&2; exit 1; }
done

mkdir -p storage/framework/{cache/data,sessions,testing,views} storage/logs bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true
rm -f public/hot

COMPOSER_ALLOW_SUPERUSER=1 composer install \
  --no-dev \
  --prefer-dist \
  --optimize-autoloader \
  --no-interaction

npm run build || true

sudo -u "$PHP_USER" php artisan down || true
SITE_DOWN=1

sudo -u "$PHP_USER" php artisan migrate --force
sudo -u "$PHP_USER" php artisan storage:link >/dev/null 2>&1 || true
sudo -u "$PHP_USER" php artisan optimize:clear
sudo -u "$PHP_USER" php artisan optimize
sudo -u "$PHP_USER" php artisan queue:restart >/dev/null 2>&1 || true

# When Redis/Supervisor have already been enabled, keep the persistent queue
# workers synchronized with the deployed code without making Redis a hard
# requirement for local/XAMPP installations.
if command -v redis-cli >/dev/null 2>&1 \
  && redis-cli ping 2>/dev/null | grep -q PONG \
  && command -v supervisorctl >/dev/null 2>&1 \
  && [[ -f deploy/flowtrack-worker.conf ]]; then
  sudo cp deploy/flowtrack-worker.conf /etc/supervisor/conf.d/flowtrack-worker.conf
  sudo sed -i "s|/var/www/flowtrack|$APP_DIR|g" /etc/supervisor/conf.d/flowtrack-worker.conf
  sudo supervisorctl reread >/dev/null
  sudo supervisorctl update >/dev/null
  sudo supervisorctl restart 'flowtrack-worker:*' >/dev/null 2>&1 || true
fi

sudo -u "$PHP_USER" php artisan up
SITE_DOWN=0
trap - EXIT

echo "FlowTrack production deployment completed successfully."
