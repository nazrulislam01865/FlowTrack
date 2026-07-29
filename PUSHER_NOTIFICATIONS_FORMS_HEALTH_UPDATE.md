# Pusher Notifications, Access Forms and Job Health

## Pusher Channels configuration

Create a Pusher Channels application and add these values to the deployed `.env` file:

```dotenv
PUSHER_APP_ID=
PUSHER_APP_KEY=
PUSHER_APP_SECRET=
PUSHER_APP_CLUSTER=ap2
PUSHER_SCHEME=https
PUSHER_PORT=443
```

Then clear Laravel's cached configuration:

```bash
php artisan optimize:clear
php artisan config:cache
```

The browser uses the official Pusher JS client. The server signs Pusher Channels REST requests directly, so this update does not require an additional Composer or npm package. Database notifications remain the source of truth if Pusher is temporarily unavailable.

## Notification recipients

Admin and Super Admin users receive all operational notifications in their workspace. Other users receive notifications only when they are connected to the Job as owner, coordinator, explicit Job member, or Task assignee.

Realtime events are generated for:

- Job creation and assignment
- Task creation and assignment
- Task status, blocker, or attention changes
- Workflow Phase movement
- Required document upload
- Job health changes
- Job member changes

Every user subscribes only to `private-flowtrack.user.{user_id}`. The authentication endpoint rejects attempts to subscribe to another user's channel.

## Job health calculation

Job health is calculated by `JobHealthSynchronizer`; boards and forms cannot set it directly. It evaluates only the Job's current Workflow Phase Tasks plus the Job delivery date, in this order:

1. `Completed`: the Job or current Phase is completed.
2. `Blocked`: a current-Phase Task has Blocked, Attention, or On Hold status.
3. `Delayed`: the delivery date has passed while the Job is active.
4. `Needs Attention`: a current-Phase Task has its attention flag or blocker reason set.
5. `At Risk`: a current-Phase incomplete Task is overdue.
6. `At Risk`: delivery is within two days while progress is below 80%.
7. `On Track`: no active blocker, overdue Task, or delivery risk exists.

The reason is stored in `flow_jobs.health_reason`, appears in the Job details, and is available as a tooltip on health badges.

## Form layout

The Add User, Edit User Access, Create Role, and Edit Role forms use a single-column layout in both the exact-template and Vue/Inertia interfaces.
