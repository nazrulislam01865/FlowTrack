# Nginx Vite Asset Cache - 2026-09-01

## Purpose

Reduce repeat page-load time and bandwidth by allowing browsers and intermediary caches to retain FlowTrack's content-hashed Vite assets for one year.

## Scope

This change is deployment/configuration only. It does not modify Laravel controllers, Livewire components, routes, permissions, workflow behavior, database logic, or UI source code.

## Implemented policy

```nginx
location ^~ /build/assets/ {
    try_files $uri =404;
    expires 1y;
    add_header Cache-Control "public, max-age=31536000, immutable" always;
    access_log off;
}
```

The reusable project snippet is `deploy/nginx-performance-snippet.conf.example`.

## Why `/build/assets/` is safe

The current production build stores generated files such as CSS and JavaScript under `public/build/assets/` and gives them content hashes in their filenames. When source content changes, Vite emits a different filename. Therefore a one-year immutable cache on these files does not make a new deployment stale.

`public/build/manifest.json` is intentionally outside this location and is not given the one-year immutable policy.

## Production installation

Copy or include the location block inside the active FlowTrack HTTPS `server` block, normally `/etc/nginx/sites-available/flowtrack`.

Before reload:

```bash
sudo nginx -t
```

Only if the test succeeds:

```bash
sudo systemctl reload nginx
```

## Verification

Choose a real file from `public/build/assets/`, then request its public URL:

```bash
curl -I https://YOUR-DOMAIN/build/assets/ACTUAL-HASHED-ASSET.css
```

Confirm:

- HTTP 200 response.
- `Cache-Control` contains `public, max-age=31536000, immutable`.
- `Expires` is approximately one year ahead.
- Application pages still load their CSS and JavaScript normally.

## Rollback

Remove the `/build/assets/` location block from the Nginx site configuration, run `sudo nginx -t`, and reload Nginx. No application or database rollback is required.
