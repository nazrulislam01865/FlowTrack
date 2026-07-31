# FlowTrack Cloud Performance Update

This build keeps the existing Blade UI and business rules while changing how data is loaded and refreshed.

## Implemented

- Active workspace membership, role, permissions and department are resolved once per user object per request.
- Authorization service is request-scoped and caches job-assignment checks.
- Initial operational data uses lightweight list endpoints with 50 records per page and no expensive total-count query.
- Jobs, Tasks, Clients and Documents have dedicated detail endpoints. Full relationships are loaded only when a record opens.
- Job and Task mutations update the affected browser records instead of running a complete `loadBootstrap()` refresh.
- Task creation/update/due-date/deletion responses no longer return the full job task collection when only one Task changed.
- Notification polling refreshes only the notification feed.
- Authority notification history synchronization no longer runs during status/feed polling or mark-all-read.
- Database notifications and Pusher delivery are queued on the `notifications` queue.
- Dashboard/report metrics use a versioned two-minute cache, invalidated by successful FlowTrack mutations.
- Composite indexes were added for Job visibility, Task board queries, Documents and notification unread/latest lookups.
- Redis/Supervisor deployment files are included without making Redis a hard requirement for local installations.

## Cloud activation

From the deployed project directory run once:

```bash
chmod +x deploy/enable-redis-performance.sh
sudo APP_DIR=/var/www/flowtrack bash deploy/enable-redis-performance.sh
```

For later code deployments, use the normal `deploy/optimize-production.sh`; it will restart configured workers automatically.

## Required post-deployment checks

```bash
redis-cli ping
sudo supervisorctl status 'flowtrack-worker:*'
php artisan migrate:status
php artisan queue:monitor notifications,default --max=100
```

A one-time sign-in may be required when changing the existing session driver from database to Redis. Existing business data is not changed or removed.
