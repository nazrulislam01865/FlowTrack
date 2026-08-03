#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/flowtrack}"
PHP_USER="${PHP_USER:-www-data}"
SUPERVISOR_CONF="/etc/supervisor/conf.d/flowtrack-worker.conf"

cd "$APP_DIR"
[[ -f .env ]] || { echo "Missing $APP_DIR/.env" >&2; exit 1; }

sudo apt-get update
sudo apt-get install -y redis-server supervisor php-redis
sudo systemctl enable --now redis-server supervisor
redis-cli ping | grep -q PONG
php -m | grep -qi '^redis$'

cp .env ".env.before-redis-$(date +%Y%m%d-%H%M%S)"
set_env() {
  local key="$1" value="$2"
  if grep -qE "^${key}=" .env; then
    sed -i "s|^${key}=.*|${key}=${value}|" .env
  else
    printf '\n%s=%s\n' "$key" "$value" >> .env
  fi
}

set_env CACHE_STORE redis
set_env SESSION_DRIVER redis
set_env SESSION_CONNECTION default
set_env QUEUE_CONNECTION redis
set_env REDIS_CLIENT phpredis
set_env REDIS_HOST 127.0.0.1
set_env REDIS_PORT 6379
set_env REDIS_CACHE_CONNECTION cache
set_env REDIS_QUEUE_CONNECTION default
set_env FLOWTRACK_LIST_PER_PAGE 50
set_env FLOWTRACK_BOOTSTRAP_SEGMENT_PER_PAGE 50
set_env FLOWTRACK_METRICS_CACHE_SECONDS 120
set_env FLOWTRACK_REALTIME_POLL_INTERVAL_MS 15000

sudo cp deploy/flowtrack-worker.conf "$SUPERVISOR_CONF"
sudo sed -i "s|/var/www/flowtrack|$APP_DIR|g" "$SUPERVISOR_CONF"
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart 'flowtrack-worker:*' || true

sudo -u "$PHP_USER" php artisan optimize:clear
sudo -u "$PHP_USER" php artisan migrate --force
sudo -u "$PHP_USER" php artisan optimize
sudo -u "$PHP_USER" php artisan queue:restart

redis-cli ping
sudo supervisorctl status 'flowtrack-worker:*'
echo "Redis sessions/cache/queues and FlowTrack workers are enabled."
