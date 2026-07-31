# Board Filter and Sticky Header Fix

Updated the Operations Board UI based on the requested screen changes.

## Changes

- Removed the `More` filter from the Board toolbar.
- Removed the `Group by` control from the quick filter row.
- Removed the `Save as view` action from the board summary row.
- Normalized the visible filter controls so Job, Client, Status, and Due Date share consistent widths.
- Reworked the Task Board sticky status header so it sticks at the top of the board canvas instead of floating over/overlapping job rows.

## Files Changed

- `public/flowtrack-dynamic.js`

## Deployment

Use existing database deployment only:

```bash
php artisan optimize:clear
php artisan migrate --force
php artisan route:cache
php artisan view:cache
```

Do not run `php artisan migrate:fresh --seed` on production.
