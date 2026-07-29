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

## Job assignment visibility and controls update

- Non-administrator Job visibility is now strictly assignment-based, regardless of a broader role record scope.
- Every participant in a visible Job can read all Tasks and subtasks in that Job.
- Dedicated Task/subtask due-date endpoints and controls were added to both interfaces.
- Administrators and original Job creators can intentionally remove Tasks and Job-specific phases.
- Intentionally removed Task Pack Tasks are not regenerated and no longer block readiness or document gates.
- Per-Job phase exclusions are respected by manual moves, drag-and-drop moves and automatic advancement.
- All 164 PHP files under `app`, `database`, `routes` and `tests` passed `php -l`.
- `public/flowtrack-dynamic.js` passed `node --check`.
- 324 TypeScript/Vue script sections were syntax-transpiled with TypeScript 5.8, and local frontend imports resolve.
- Composer/Pest and the full Vite build could not be run in this environment because `vendor` and `node_modules` were not included and package installation was unavailable. Run `composer install`, `npm install`, `php artisan test` and `npm run build` after extraction.

## Session middleware lifecycle fix (2026-07-29)

- Confirmed that `EnforceFlowTrackSession` no longer declares a `terminate()` method, preventing Laravel from treating it as terminable middleware.
- PHP syntax validation completed for all project PHP source files.
- Generated Laravel route/config/view/session caches were excluded from the corrected archive.

## Notification authority feed and fallback delivery

- Added workspace-wide authority feed synchronization with independent read state.
- Added shared event/workspace/audience metadata to new database notifications.
- Added `/flowtrack/notifications/status` and 20-second polling fallback in both interfaces.
- Added notifications for due-date changes, task removal, and Job Phase removal.
- Added feature tests for authority copies, private user visibility, independent read state, and status payload.
