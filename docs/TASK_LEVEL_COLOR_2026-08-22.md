# Task-level color — 2026-08-22

Task color belongs to each Task Pack item, not to the Task Pack itself.

## Configuration
- Task Pack Setup exposes a color picker and hex input for every individual task.
- The value is stored in `task_pack_items.color` and mirrored to legacy `task_pack_tasks.color` when that compatibility table exists.
- The Task Pack itself has no color setting.

## Runtime display
The configured task color is surfaced as the task accent in:
- Order detail workflow tasks
- Inquiry Taskflow tasks
- My Tasks
- All Tasks / board task lists
- Task Pack Setup task rows

## Compatibility
The migration detects the short-lived earlier `task_packs.color` implementation. If present, it copies that value to the pack's individual tasks and then removes the pack-level column.

No Inquiry or Order workflow execution, branching, dependency, document, assignment, or status-transition logic is changed by this update.
