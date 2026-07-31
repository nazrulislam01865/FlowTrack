# FlowTrack Performance Implementation Verification

## Completed application changes

- Current workspace membership, role, permissions and department are cached on the authenticated User object for the lifetime of each request.
- FlowAuthorizationService is request scoped and reuses assignment checks.
- Initial Clients, Jobs, Tasks and Documents load in bounded pages using `simplePaginate`.
- Paginated Task and Document pages include only their bounded related Job/Client lookups, preventing missing labels without restoring a full-database download.
- Dedicated Client, Job, Task and Document detail endpoints load full relationships only when a record is opened.
- Task and Job mutation handlers update or remove only the affected browser records.
- Notification polling uses the lightweight notification feed and no longer synchronizes historical authority notifications.
- Database notifications and Pusher events are queued on the `notifications` queue.
- Dashboard metrics are cached for 120 seconds by default and invalidated after successful FlowTrack mutations.
- Composite indexes are supplied in migration `2026_07_31_210000_add_flowtrack_performance_indexes.php`.
- Redis and Supervisor activation is supplied in `deploy/enable-redis-performance.sh`.

## Verification completed on this package

- PHP syntax lint passed for all PHP files under `app`, `config`, `database/migrations`, and `routes`.
- JavaScript syntax check passed for `public/flowtrack-dynamic.js`.
- `npm run build` passed using the project's Blade asset verifier.
- Shell syntax checks passed for both deployment scripts.
- All TemplateApiController route method references resolve to controller methods.
- Migration index names are within MySQL's identifier limit.

## Cloud activation

Run once after uploading the project:

```bash
cd /var/www/flowtrack
chmod +x deploy/enable-redis-performance.sh deploy/optimize-production.sh
sudo APP_DIR=/var/www/flowtrack bash deploy/enable-redis-performance.sh
```

Then verify:

```bash
redis-cli ping
sudo supervisorctl status 'flowtrack-worker:*'
php artisan migrate:status
php artisan queue:monitor notifications,default --max=100
```

Switching an existing installation from database sessions to Redis can require users to sign in once again. The migration adds indexes only and does not delete business data.
