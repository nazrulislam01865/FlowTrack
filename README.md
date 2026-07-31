# FlowTrack Exact Design — Laravel Blade

This package uses the supplied FlowTrac screen design as the canonical UI. The workspace is served through `resources/views/flowtrack-template.blade.php` with vanilla JavaScript assets in `public/`. Vue and Inertia are not mounted.

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
FILESYSTEM_DISK=public
```

Install and seed:

```bash
php artisan optimize:clear
php artisan migrate:fresh --seed
rm -f public/storage
php artisan storage:link
php artisan serve
```

Open `http://127.0.0.1:8000`.

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
php artisan migrate:fresh --seed
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

This verifies the Blade/vanilla assets. No Vite/Vue/Inertia dev server is required for the UI.
