# FlowTracker — Modular Laravel build

This build preserves the supplied FlowTracker design and backend rules while reorganizing the application toward the compact Laravel structure used by the supplied Hisebghor project.

The key architecture notes are in:

- `MODULAR_CONVERSION_REPORT.md`
- `FRONTEND_MODULAR_STRUCTURE.md`
- `FLOWTRACK_LAZY_ASSET_SPLIT.md`
- `FLOWTRACK_PERFORMANCE_OPTIMIZATION.md`

## Architecture

- Route-specific page controllers: `app/Http/Controllers/FlowTrack/Pages/`
- Responsibility-specific API controllers: `app/Http/Controllers/FlowTrack/Api/`
- Business Actions/Services: `app/Modules/OrderFlow/`
- Route/page registry: `app/Support/FlowTrackPage.php`
- Shared page response setup: `app/Services/FlowTrack/PageResponseService.php`
- Canonical frontend source: `resources/js/flowtrack/`
- Modular CSS source: `resources/css/flowtrack/`
- Generated runtime assets: `public/flowtrack/`

Generated files under `public/flowtrack/` must not be edited directly.

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
npm ci
npm run build
php artisan optimize:clear
php artisan migrate --force
php artisan serve
```

For a brand-new disposable local database only, `php artisan migrate:fresh --seed` can be used. Never run `migrate:fresh`, `db:wipe`, or demo seeders against an existing FlowTracker database.

## Upgrade an existing installation

Keep the production `.env`, database and required storage/uploads. Replace the application files, then run:

```bash
composer install --no-interaction --prefer-dist --optimize-autoloader
php artisan optimize:clear
php artisan migrate --force
npm ci
npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Then verify:

```bash
php artisan flowtrack:doctor
php artisan test
```

## Frontend build

```bash
npm run build
```

The build:

1. generates route-aware FlowTracker runtime modules from `resources/js/flowtrack/`,
2. compiles the unchanged modular CSS through Vite,
3. verifies route-page structure, generated assets and preserved UI markers.

FlowTracker no longer mounts its UI through the previous fake Livewire screen shell. Vue and Inertia are not used.

## Production notes

- Use HTTPS and secure/encrypted sessions in production.
- Run additive migrations with `php artisan migrate --force`.
- Keep uploaded documents outside the public web root as configured by the project.
- For Redis/session/queue tuning, use the supplied deployment helpers only after reviewing the production `.env`.
