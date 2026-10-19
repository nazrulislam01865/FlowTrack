# My Tasks - Active Task Only Visibility

Date: 2026-08-25

## Requirement

Keep the current workflow-stage cards and restored previous table/filter design, but under each Order show only the currently active Order task.

Visibility rules for that active task:

1. The task assignee can see it.
2. The Order creator can see it.
3. A user included by the configured task access scope can see it.
4. Admin and Super Admin are exempt from the participant/access gate, but they still see only active task rows, not every task in the Order.

Completed, locked, skipped, previous-phase and future-phase tasks are not rendered in My Tasks.

## File 1 - app/Services/MyWorkService.php

### New authoritative query

`activeVisibleTaskQuery(User $user)` is now the common source for My Tasks.

For non-admin users it creates the union of:

- `tasks.assignee_id = current user`
- parent Order `created_by = current user`
- task is visible through `TaskService::visibleQuery($user)` / configured record scope

Admin and Super Admin skip that participant restriction.

Every role is then restricted to:

- non-completed task
- active client / active Order
- non-completed Order
- task phase equals the Order's current workflow phase
- task status is not locked/not started/skipped/completed/cancelled or a waiting blocker

### My Tasks table

`personalTaskQuery()` always starts from `activeVisibleTaskQuery()`. Filters only narrow that active set and cannot bring historical/future tasks back.

### Counts

The stage-card counts, sidebar/open-task count, Order-id scope and My Tasks metrics now use the same active-visible scope so counts do not include hidden sibling tasks.

### Compatibility

`activeAssignedTaskQuery()` is kept as a backwards-compatible wrapper for callers that specifically need `active + assigned to this user`.

## File 2 - app/Livewire/MyWork/Index.php

After any Order task status update, `refresh` is always true for every role. This is necessary because completing/changing an active task can unlock the next task or advance the Order phase. Admin/Super Admin now use the same active-only row behavior.

## File 3 - resources/views/livewire/my-work/index.blade.php

Only the explanatory subtitle was updated. The workflow-stage header/cards and previous table/filter design are unchanged.

## Result examples

If an Order has:

- Task 2.1 = Completed
- Task 2.2 = Ready (current active task)
- Task 2.3 = Not Started
- Task 2.4 = Not Started

My Tasks shows only Task 2.2.

For a non-admin, Task 2.2 is shown if the user is the assignee, the Order creator, or their configured task scope grants visibility.

For Admin/Super Admin, Task 2.2 is shown regardless of assignment/creator, but Tasks 2.1/2.3/2.4 are still hidden.

## Deployment

No migration is required.

Run:

```bash
php artisan view:clear
php artisan optimize:clear
```
