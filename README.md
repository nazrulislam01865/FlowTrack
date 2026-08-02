# FlowTrack Exact Design — Laravel Livewire

This package uses the supplied FlowTrac screen design as the canonical UI. The workspace is served through Livewire 4 component boundaries while preserving the existing Blade template, CSS, JavaScript compatibility layer, backend services, and API contracts. Vue and Inertia are not mounted.

See `LIVEWIRE_CONVERSION.md` for the component structure and migration safeguards,
`FLOWTRACK_LAZY_ASSET_SPLIT.md` for route-level JavaScript loading, and
`FRONTEND_MODULAR_STRUCTURE.md` for the Blade/CSS/Vite module map.

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Create the MySQL database, then update `.env` if your local values differ:

```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS flow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=flow
DB_USERNAME=root
DB_PASSWORD=
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
FLOWTRACK_DOCUMENTS_DISK=flowtrack_documents
```

For a new empty local database only, install and seed:

```bash
php artisan optimize:clear
php artisan migrate:fresh --seed
rm -f public/storage
php artisan storage:link
php artisan serve
```

Open `http://127.0.0.1:8000`.

Never run `migrate:fresh`, `db:wipe`, or the demo seeder against an existing
FlowTrack database.

## Upgrade an existing FlowTrack installation

This release includes safe additive repairs for document-to-task linkage and
concurrency-safe record numbering. It
fixes the `Unknown column 'flow_task_id'` failure without deleting Jobs,
documents, users, uploads, or any other existing data. After replacing the
project files, keep the existing `.env` and `storage` directory, then run:

```bash
composer install --no-interaction
php artisan optimize:clear
php artisan migrate --force
npm ci
npm run build
php artisan test
```

Do not omit `php artisan migrate --force`: clearing Laravel's cache and building
frontend assets cannot repair an out-of-date MySQL schema.

Verify the live upload prerequisites without changing data:

```bash
php artisan flowtrack:doctor
```

## Document upload deployment

FlowTrack accepts PDF, Office, image and ZIP documents up to 20 MB. New uploads
are stored in `storage/app/flowtrack-documents`, outside the public web root, and
are served only through Laravel's authorized preview/download controller.

For Nginx/PHP-FPM, install the supplied limits and include the supplied Nginx
fragment in the HTTPS server block:

```bash
sudo cp deploy/99-flowtrack-uploads.ini /etc/php/8.3/fpm/conf.d/99-flowtrack-uploads.ini
sudo systemctl restart php8.3-fpm
# include deploy/nginx-flowtrack-performance.conf from the site server block
sudo nginx -t && sudo systemctl reload nginx
```

The PHP version/path may differ on your server. The required effective values
are `upload_max_filesize=25M`, `post_max_size=30M`, and Nginx
`client_max_body_size 25m`.

On macOS/XAMPP, `.user.ini` is not used by PHP CLI and may also be omitted by
some archive/upload tools because it is hidden. Configure the PHP installation
that actually runs FlowTrack with the included helper:

```bash
bash scripts/configure-xampp-upload-limits.sh
# Restart XAMPP Apache, or restart php artisan serve.
php artisan flowtrack:doctor
```

The helper discovers the loaded `php.ini` through `php --ini` and creates a
timestamped backup before changing the five upload/runtime directives.

## Production security and concurrency

Set `APP_URL` to the HTTPS URL, `APP_ENV=production`,
`FLOWTRACK_FORCE_HTTPS=true`, `SESSION_ENCRYPT=true`, and
`SESSION_SECURE_COOKIE=true`. Login credentials use a native form: application
JavaScript does not read, store, log or return the email/password. Laravel
rate-limits authentication, regenerates the session ID and hashes all created
user passwords. Browsers still necessarily receive user keystrokes, so HTTPS,
trusted devices and browser/password-manager policy remain required.

For 30–40 active staff, run `deploy/enable-redis-performance.sh` after setting
up the production `.env`. It configures Redis-backed sessions/cache/queues and
two supervised workers. The fallback realtime poll is 15 seconds and list
payloads are bounded to 50 records by default.

## Test logins

```text
Super Admin
Email: superadmin@flowtrack.cn
Password: SuperAdmin12345

Manager
Email: manager@flowtrack.cn
Password: demo123
```

## What is dynamic

- Dashboard metrics, recent activity and notifications are returned by Laravel endpoints.
- Clients, jobs, tasks, documents, notifications, workflows, task packs, master data, roles and users are database-backed.
- Job creation activates the selected workflow phase and generates tasks from the configured task pack.
- Required document tasks support upload, preview and download.
- Board movement, task completion, comments, mentions, due date changes, assignee changes and notifications go through Laravel controllers/services.
- Document deletion removes stored physical file versions.
- Workflow Setup, Task Pack Setup and Master Data changes are persisted and immediately reflected through the bootstrap payload.

## Verification commands

After `composer install`, run:

```bash
npm run build
php artisan optimize:clear
php artisan migrate --force
php artisan route:list
php artisan test
```

Or run the included helper:

```bash
bash scripts/verify-flowtrack.sh
```

## Frontend assets

```bash
npm run build
```

This regenerates the route-aware JavaScript modules, copies the fallback asset,
compiles the modular CSS through Vite, and verifies the Livewire/DOM/design
contract. Vue and Inertia are not used. Tailwind preflight is intentionally not
enabled because the existing custom CSS is the exact design source of truth.
