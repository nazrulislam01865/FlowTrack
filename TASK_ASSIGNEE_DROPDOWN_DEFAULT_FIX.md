# Task Assignee Dropdown and Default Assignment Fix

## Issue
Task Details could show an assignee in the summary card while the editable Assignee dropdown still showed `Unassigned`, and the workspace member list could be missing for Admin/Super Admin users.

## Fix
- Admin/Super Admin bootstrap now always loads all active workspace members for assignment dropdowns.
- Task API now returns the effective assignee. If a Task has a Task Pack Setup default assignee and Admin/Super Admin has not overridden it, that default is used for both the card and dropdown.
- Task Details JavaScript now hydrates task assignee data before rendering, so the summary card and dropdown stay in sync.
- The dropdown now includes the current/default assignee even if a permission-filtered user list is temporarily incomplete.
- Added a new safe data-repair migration to restore default assignees for existing generated tasks where no manual override exists.

## Deployment
Run only:

```bash
php artisan optimize:clear
php artisan migrate --force
php artisan route:cache
php artisan view:cache
```

Do not run `migrate:fresh --seed` on production.
