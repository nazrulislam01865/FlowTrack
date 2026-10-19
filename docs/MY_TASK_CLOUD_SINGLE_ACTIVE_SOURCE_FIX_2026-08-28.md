# My Tasks cloud single-active-task source fix

Date: 2026-08-28

## Symptom

On cloud data, My Tasks could show multiple tasks from the same Order as active while Order Details correctly showed only one active task.

## Root cause

Order Details resolves its active task structurally from the Order's current workflow phase and the saved Task Pack sequence (`OrderDetailPresenter::nextTask()`). My Tasks previously inferred active work mainly from phase + stored task status. Older cloud Orders can retain stale sibling statuses after workflow/task-pack revisions, so more than one row can look active even though only one is actually next in sequence.

My Tasks also did not explicitly exclude generated task rows that belong to an obsolete Task Pack but still point at the same workflow phase.

## Fix

`app/Services/MyWorkService.php` now:

- requires generated tasks to belong to the current phase's saved Task Pack;
- resolves the first incomplete required generated task by Task Pack `sort_order` + task id;
- preserves the Sample Approval and QC Issue conditional branch behavior;
- shows only the first manual task after required generated work is finished;
- only falls back to an activated optional task after required/manual work;
- applies user/creator/access-scope visibility to the structurally active row, so a future assigned sibling cannot make the Order appear early;
- keeps Admin/Super Admin assignment exemption while still enforcing one active row per Order.

This removes dependency on stale READY / IN PROGRESS statuses as the source of truth.

## Database

No data migration is required. Existing cloud Orders are corrected at read time because My Tasks now follows the saved workflow structure instead of stale sibling status values.

## Deployment

After deploying the code:

```bash
php artisan optimize:clear
php artisan view:clear
php artisan queue:restart
```

No database migration is required for this fix.
