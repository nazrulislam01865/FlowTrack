# FlowTrack deployment and rollback

## Build from a clean revision

```bash
composer install --no-dev --classmap-authoritative --no-interaction --prefer-dist
npm ci
npm run build
npm run quality:bundle
php artisan migrate --force
php artisan optimize:clear
php artisan optimize
php artisan queue:restart
```

Run `php artisan flowtrack:infrastructure:check --prepare-storage` before adding a horizontally scaled node to the load balancer. `/health/ready` is the traffic-readiness endpoint; `/up` is liveness only.

Production horizontal mode uses Redis cache/session/queues, independently supervised queue workers/Reverb/scheduler, and shared/object storage. See `docs/infrastructure-scalability.md`.

## Before schema/deployment changes

Create and verify a database backup with `php artisan flowtrack:database:backup`. Restore drills use `php artisan flowtrack:database:restore ... --force`; see `docs/backup-restore.md`.

## Rollback

Application rollback deploys the previous tested revision and rebuilds its dependencies/assets from that revision's lockfiles. Phase 14 infrastructure changes are configuration-level and retain the single-node rollback profile. Never roll back a database migration by editing migration history; use the documented forward/rollback procedure and restore only when explicitly required.

## Nginx gzip compression

FlowTrack's Nginx performance snippet also enables gzip for text-based responses larger than 1 KB. Compression level 6 provides a practical balance between transfer-size reduction and CPU cost. The policy intentionally excludes already-compressed raster formats such as JPEG, PNG and WebP.

```nginx
gzip on;
gzip_vary on;
gzip_comp_level 6;
gzip_min_length 1024;

gzip_types
    text/plain
    text/css
    text/xml
    application/json
    application/javascript
    application/xml
    application/xml+rss
    image/svg+xml;
```

After changing Nginx configuration, always validate before reload:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

A compressed CSS/JS response can be checked with:

```bash
curl -I -H 'Accept-Encoding: gzip' https://YOUR-DOMAIN/build/assets/ACTUAL-HASHED-ASSET.css
```

The response should include `Content-Encoding: gzip` and `Vary: Accept-Encoding`.

## Nginx cache policy for Vite assets

FlowTrack's production Vite build writes content-hashed CSS and JavaScript files to `public/build/assets/`. Serve only that hashed asset directory with a long immutable browser cache. The ready-to-include configuration lives at:

```text
deploy/nginx-performance-snippet.conf.example
```

Inside the site's HTTPS `server` block, use the equivalent of:

```nginx
location ^~ /build/assets/ {
    try_files $uri =404;
    expires 1y;
    add_header Cache-Control "public, max-age=31536000, immutable" always;
    access_log off;
}
```

Do not broaden this immutable rule to `/build/`, because `public/build/manifest.json` is not content-hashed. A new Vite build changes asset filenames automatically, so previously cached assets do not prevent users from receiving newly deployed CSS or JavaScript.

After changing the server configuration, validate before reloading Nginx:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

Verify a deployed hashed asset returns the long-lived cache header:

```bash
curl -I https://YOUR-DOMAIN/build/assets/ACTUAL-HASHED-ASSET.css
```

Expected response headers include `Cache-Control: public, max-age=31536000, immutable` and an expiry approximately one year in the future.
