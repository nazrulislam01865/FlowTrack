# Clickable Phase History and Sub Task Management Update

This update adds the requested Job Workflow Details improvements:

1. Completed/current workflow phases are clickable from the Job Workflow tab.
2. Clicking a phase opens its task history panel showing:
   - phase status
   - entered/completed dates
   - who last changed/completed the phase
   - completed task count
   - task list for that phase
   - task activity/change history
3. After creating a Job, the UI opens the full Job Details page and shows the phase sub tasks instead of returning only to the Jobs list.
4. Admin/Super Admin can add a sub task directly from Job Details.
5. Admin/Super Admin can open a sub task and edit:
   - title
   - description
   - status
   - priority
   - assignee
   - due date
   - comment/mentions
6. Admin/Super Admin can remove a sub task from the Task Details action menu.

Backend additions:

- `GET /flowtrack/template-api/jobs/{job}/tasks`
- `POST /flowtrack/template-api/jobs/{job}/tasks`
- Task update accepts title, description, and priority.
- Task API response now includes completed-by information.
- Job API response now includes phase history.

Deployment note:

No database reset is required. Run only:

```bash
php artisan optimize:clear
php artisan migrate --force
php artisan route:cache
php artisan view:cache
```
