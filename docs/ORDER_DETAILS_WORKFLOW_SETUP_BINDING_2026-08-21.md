# Order Details -> Order Workflow Setup binding

## Source of truth

Order Details no longer contains a hard-coded seven-stage/task definition.

The workflow shown on an Order is read from that Order's backend workflow snapshot:

1. **Order Workflow Setup** persists the reusable Order workflow, its seven stages, stage colors and Task Pack items.
2. **Create Order** snapshots that workflow into a private Order workflow before operational tasks are created.
3. `JobService::syncWorkflowTasks()` creates the Order tasks from the snapshotted Task Pack items.
4. **Order Details** renders `FlowJob -> workflow -> phases` and the already-loaded `FlowJob -> tasks` collection.

This means the Order Details UI and backend task engine read the same data. There is no second stage/task definition in Blade or JavaScript.

## Why Orders use a snapshot

Changing Order Workflow Setup must not silently rewrite historical or in-progress Orders. New Orders use the newly saved configuration; existing Orders keep the configuration they were created with. This preserves audit history, task identifiers, completed work and document requirements.

## Runtime rules

- Stage order/name/color comes from the Order workflow snapshot.
- Task order/title/default assignment/due offset/document requirement comes from the snapshotted Task Pack.
- Required task sequencing is enforced by `OrderTaskSequenceService`.
- Completion/document validation remains in backend services.
- Stage selection in Order Details is presentation-only; future stages cannot be opened until active.
- No workflow definitions or dependency rules are stored in Order Details JavaScript.

## Query behavior

`JobService::loadVisibleDetailTab()` eager-loads the workflow phases, Task Pack items, visible tasks, assignees, document configuration, documents and task links. The Order Details workflow Blade/components only use those loaded relations, avoiding per-stage/per-task database queries.
