# Order Details materialized workflow summary — performance pass 7 (2026-09-10)

## Goal

Reduce the time from the Orders list to the first usable Order Details paint without changing Order workflow, Task Pack, task sequencing, hold, permission, auto-advance, shipment, artwork, redo, or reporting business rules.

## What changed

The initial Order Details parent no longer hydrates the Workflow phase graph and current-stage Task relations just to render the three workflow summary cards.

A derived table, `order_workflow_summaries`, stores only the small read model needed by the always-visible cards:

- workflow / current phase identity and labels
- stage count
- current-stage task counts
- next task id/title/assignee id
- persisted Order progress/status
- hold state
- stale/refreshed metadata

`OrderWorkflowSummaryService` rebuilds this row from the existing authoritative FlowJob, WorkflowPhase and generated Task rows. It reuses `OrderDetailPresenter::currentTasks()` and `OrderDetailPresenter::nextTask()` instead of introducing a second set of workflow rules.

The complete Workflow/Task Pack definition is **not duplicated per Order**. Existing Order-specific generated Task rows remain the runtime state. The summary is disposable/repairable derived data only.

## Initial navigation path

Before this pass the parent overview path called `loadOverviewShell()`, which loaded workflow stages, the current Task Pack and current-stage tasks before the page could render.

The overview parent now loads:

1. the existing compact authorized Order base row/relations;
2. one materialized workflow summary row for shared/full task visibility;
3. the existing lightweight planning/shipping/redo shell context.

Products, Workflow, Attachments and Activity remain isolated progressive Livewire children. The full authoritative Workflow child still performs runtime reconciliation before exposing task actions.

Order links in the Orders table now use `wire:navigate.hover`, allowing Livewire 4 to prefetch the Order route when the user intentionally hovers a link before clicking it.

## Permission safety

A shared summary can be used directly only by:

- administrators;
- the Order creator; or
- users whose task scope includes `all_records`.

For a restricted task scope, the service calculates the task-sensitive part from a bounded current-phase permission-scoped query. This preserves the existing behavior where a user must not learn about a task outside their task visibility.

## Selective invalidation

The read model is marked stale only when relevant runtime/configuration state changes:

- task status/progress lifecycle (`JobService::recalculateProgress`);
- task claim / workflow handoff assignee updates;
- checklist progress;
- task external-link evidence;
- task document upload/link/delete evidence;
- Task Pack item edits;
- mapped Order workflow definition changes;
- Order workflow rebinding/reconciliation.

Hold/unhold updates the tiny cached hold flag directly, while the existing OrderHold table and backend guards stay authoritative.

Task Pack item edits continue to update only generated tasks matching the changed `task_pack_task_id`. The summary invalidation then targets affected Orders only. A brand-new Task Pack item, pack reorder/removal, or other structural change may invalidate the mapped workflow summaries because no generated row exists yet to target safely; the existing lazy binding/synchronization path remains authoritative for that structural repair.

The ordinary Order description `@mention` directory is intentionally preserved on the initial shell so this performance pass does not remove existing editor functionality.

## Important invariants preserved

- `OrderDetailPresenter::nextTask()` remains the next-action rule.
- `OrderTaskRequirement` remains the required/optional/conditional rule.
- `TaskService`, `OrderWorkflowActionService` and `OrderWorkflowBindingService` remain authoritative for writes.
- Hold blocking continues to be enforced by `OrderHoldService` in backend write paths.
- No workflow/action endpoint or task-completion rule was replaced by the read model.
- The read model can be deleted and rebuilt without losing Order workflow state.

## Deployment

Run the migration first, then prebuild the summaries so existing Orders do not pay the one-time rebuild cost on first open:

```bash
php artisan migrate --force
php artisan flowtrack:rebuild-order-workflow-summaries --chunk=100
php artisan optimize:clear
```

Suggested regression tests:

```bash
php artisan test --filter=OrderWorkflowSummaryReadModelPerformanceTest
php artisan test --filter=OrderDetailsFullIsolationPerformanceTest
php artisan test --filter=OrderOverviewSummaryRealtimeRegressionTest
php artisan test --filter=OrderWorkflowActiveTaskActionRegressionTest
php artisan test --filter=OrderWorkflowTaskButtonsRegressionTest
```

No JS/CSS source changed, so `npm run build` is not required for this patch.

## Verification checklist

1. Open an Order from the list and confirm the header/current stage/progress/next action appear before lower progressive sections finish.
2. Complete a task without refreshing and confirm progress/next action updates correctly.
3. Hold and unhold an Order without refreshing and confirm Workflow task actions lock/unlock immediately.
4. Edit a Task Pack task title/requirement and verify only its generated Task rows and affected Order summaries are refreshed.
5. Save Workflow Setup and verify active Orders keep the existing synchronization behavior.
6. Test a restricted non-admin task user and verify the Next required action still respects that user's task scope.

## Validation performed in the supplied archive

All changed/new PHP files and changed Blade files pass `php -l`. The supplied archive does not contain `vendor/`, so the Laravel PHPUnit suite cannot be executed inside this extracted copy. Run the commands above in the normal development/deployment installation after dependencies are present.
