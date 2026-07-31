# Task Setup Phase Display + Progress Loading Update

Implemented requested changes:

- Job Details phase task rows now use the generated Task Pack Setup order, not due-date order.
- Job Details phase task rows no longer show extra Add Task/action controls in the phase task section.
- Task rows resolve the effective assignee using manual assignee override first, then Task Pack Setup default assignee.
- Task rows show the latest due date after updates.
- Workflow phase history shows selected phase tasks with the same effective assignee and latest due date.
- Replaced spinner-style workspace/refresh loading with progress-bar feedback.
- Job Details refreshes job tasks after opening so latest assignee/due-date values are applied.

Deploy with existing DB only:

php artisan optimize:clear
php artisan migrate --force
php artisan route:cache
php artisan view:cache

Do not run migrate:fresh --seed on production/cloud.
