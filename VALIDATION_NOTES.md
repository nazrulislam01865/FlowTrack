# Validation notes

Validated in the generated source package:

- PHP syntax passed for all application, configuration, migration, route and test PHP files.
- `public/flowtrack-dynamic.js` passed Node syntax validation.
- Admin/Super Admin role codes are forced to all modules, all actions, `all_records`, and every sensitive field.
- Super Admin seed is idempotent and reads credentials from `.env`.
- User creation/deletion endpoints are restricted to Admin/Super Admin.
- Admin cannot alter a Super Admin account or assign the Super Admin role.
- Role matrix checks are applied to clients, Jobs, tasks, documents, reports, global search, workflow, master data and administration.
- Legacy Laravel permission slugs are synchronized from the matrix for older Inertia routes.
- Runtime storage directories and configuration are included.

The container did not include Composer dependencies, so Laravel route boot, migrations, database integration tests and the full test suite could not be executed here. Run these after extraction:

```bash
composer install
php artisan migrate
php artisan db:seed --class="Database\\Seeders\\FlowTrack\\SuperAdminSeeder"
php artisan route:list --path=flowtrack
php artisan test
```

## Logout and Master Data update

- Added dedicated Laravel logout endpoint and session invalidation.
- Added no-store page responses and browser back/forward cache refresh handling.
- Removed hardcoded Master Data records from the active Blade template.
- Added Laravel-generated Master Data section catalogue.
- Added database-backed delete actions for every Master Data section.
- Existing operational records are preserved when a Master Data record is deleted.

## Session and profile repair

- Authenticated login-route redirect added.
- Authenticated page bootstrap loader added to prevent login-screen flashing.
- Prototype profile values removed from the active profile renderer.
- Exact-template profile, password, avatar and preference endpoints added.
- PHP syntax checked across application, migrations, routes and tests.
- Inline template JavaScript and `public/flowtrack-dynamic.js` passed Node syntax checks.
