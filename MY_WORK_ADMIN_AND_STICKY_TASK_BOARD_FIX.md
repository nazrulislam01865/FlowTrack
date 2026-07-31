# My Work Admin View and Sticky Task Board Header Fix

## Changes

- Admin and Super Admin now see all visible tasks/jobs in My Work instead of only their own assigned tasks.
- Regular users still see only their own assigned tasks in My Work.
- My Work badge now counts all open tasks for Admin/Super Admin and own open tasks for normal users.
- My Work labels dynamically show admin review wording for Admin/Super Admin.
- Operations Task Board and My Work task board now use an internal vertical scroll area with a sticky status header.
- The task status header remains visible while the job-based task rows scroll vertically.

## Deployment

Use existing DB deployment only:

```bash
php artisan optimize:clear
php artisan migrate --force
php artisan route:cache
php artisan view:cache
```

Do not run `migrate:fresh --seed` in production.
