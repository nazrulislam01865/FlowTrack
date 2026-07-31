# Phase Activity + Completed Task Lock Update

## What changed

- Job Workflow phase steps remain clickable.
- The Phase Activity panel now shows the full task audit stream for the selected phase instead of only a short list.
- Each activity row shows:
  - actor name/initials
  - task code and task title
  - exact time
  - current task status
  - uploaded file name when a document completed the task
  - document requirement key when applicable
  - field-by-field changes such as Status, Progress, Due date, Assignee and Priority
  - whether the record is a completion event
- Completed tasks are now locked for non-admin users.
- Only Admin and Super Admin can edit/update/reopen/reassign/change due date for a completed task.
- Assigned users can still view completed tasks, uploaded documents, comments and full history.

## Backend enforcement

The lock is enforced in `FlowAuthorizationService`, not only hidden in JavaScript:

- `canUpdateTask()` returns false for completed tasks unless the actor is Admin/Super Admin.
- `canChangeTaskDueDate()` returns false for completed tasks unless the actor is Admin/Super Admin.

## Files changed

- `app/Modules/OrderFlow/Business/Services/FlowAuthorizationService.php`
- `app/Http/Controllers/FlowTrack/TemplateApiController.php`
- `public/flowtrack-dynamic.js`
