# FlowTrack

FlowTrack is a Laravel 13 order-flow management application. The supplied prototype remains the visual source of truth while Laravel provides authentication, database persistence, workflow logic, users, roles and system-wide authorization.

## Current implementation

- Exact FlowTrack template served through Blade.
- Database-backed clients, Jobs, tasks, workflows, Task Packs, master data, documents and notifications.
- Dynamic client and Job forms.
- Automatic task and Job progress.
- Automatic immutable Master Data and Task Pack codes.
- Working Workflow Setup and Task Pack editors.
- Database-backed Roles & Policies and Permission Matrix.
- Complete Add/Edit/Delete User form.
- Full Admin and Super Admin control.
- Super Admin credentials seeded from `.env`.

## Existing database update

Set the Super Admin values in `.env`:

```dotenv
FLOWTRACK_SUPER_ADMIN_NAME="Your Super Admin Name"
FLOWTRACK_SUPER_ADMIN_EMAIL=superadmin@yourcompany.com
FLOWTRACK_SUPER_ADMIN_PASSWORD="ReplaceWithAUniqueStrongPassword123!"
```

Then run:

```bash
composer install
php artisan migrate
php artisan db:seed --class="Database\\Seeders\\FlowTrack\\SuperAdminSeeder"
php artisan storage:link
php artisan optimize:clear
php artisan serve --host=127.0.0.1 --port=8000
```

Open `http://127.0.0.1:8000` and sign in with the `.env` Super Admin credentials.

Do not run `migrate:fresh` on an existing installation.

## Fresh installation

```bash
cp .env.example .env
composer install
php artisan key:generate
# Update DB_* and FLOWTRACK_SUPER_ADMIN_* in .env
php artisan migrate:fresh --seed
php artisan storage:link
php artisan optimize:clear
php artisan serve --host=127.0.0.1 --port=8000
```

The exact FlowTrack interface uses `public/flowtrack-dynamic.js` and does not require a Vite build. `npm install && npm run build` is optional for the included Vue/Inertia source.

See `ADMIN_ACCESS_SETUP.md` and `FLOWTRACK_SETUP.md` for details.

## Profile and session

The exact-template shell now renders authentication state server-side and loads the real signed-in user before displaying the dashboard. Profile fields, password, photo and preferences persist through `/flowtrack/template-api/profile` endpoints. See `PROFILE_SESSION_UPDATE.md`.
