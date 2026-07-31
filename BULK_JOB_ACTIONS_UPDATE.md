# Bulk Job Actions Update

Implemented for the Jobs page:

- Header checkbox selects all currently visible Jobs.
- Row checkboxes keep selected state across re-render/filter changes.
- Bulk action bar appears after selecting Jobs.
- Bulk deactivate sets selected Jobs to inactive status and removes them from active job views.
- Bulk delete is available only for Admin/Super Admin and permanently deletes selected Jobs using the existing delete action, including related Tasks, Documents, and stored document files.
- Backend route added: `PATCH /flowtrack/template-api/jobs/bulk`.
- Existing database data is preserved; no fresh migration or reseeding is required.

Deploy with existing DB:

```bash
php artisan optimize:clear
php artisan migrate --force
php artisan route:cache
php artisan view:cache
```
