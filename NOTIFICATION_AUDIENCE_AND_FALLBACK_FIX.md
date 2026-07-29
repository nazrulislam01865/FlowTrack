# Notification Audience and Delivery Fix

## Behaviour

- A normal user sees only notifications addressed to that user.
- Super Admin, Admin, and configured authority roles receive a workspace-wide notification feed.
- Authority accounts receive their own copy of each event, so reading an authority copy does not alter the original recipient's unread state.
- Existing workspace notifications are backfilled into an authority account when its feed is first loaded. This fixes empty feeds for Super Admin accounts created after demonstration or production notifications already existed.
- Every new notification now carries one shared event ID, workspace ID, timestamp, and recipient audience metadata.
- New task assignments, task status/blocker changes, task due-date changes, task comments, task removals, Job Phase changes/removals, document uploads, Job health changes, Job assignments, and phase-readiness events are persisted in the database.

## Delivery reliability

Database notifications are the source of truth. Pusher remains an optional realtime transport. Both interfaces poll `/flowtrack/notifications/status` every 20 seconds, so notifications and unread counts continue updating if Pusher is disabled, unavailable, or configured with invalid credentials.

## Authority roles

The default workspace-wide notification roles are:

- `SUPER_ADMIN`
- `ADMIN`
- `SYS_ADMIN`
- `ADMINISTRATOR`
- `SYSTEM_ADMINISTRATOR`
- `OPERATIONS_MANAGER`

Override them with:

```env
FLOWTRACK_NOTIFICATION_AUTHORITY_ROLE_CODES=SUPER_ADMIN,ADMIN,OPERATIONS_MANAGER
FLOWTRACK_NOTIFICATION_AUTHORITY_SYNC_LIMIT=2000
FLOWTRACK_NOTIFICATION_AUTHORITY_SYNC_INTERVAL_SECONDS=60
```

## Deployment

```bash
php artisan optimize:clear
php artisan migrate
npm install
npm run build
php artisan serve
```

No new database migration is required for this update.
