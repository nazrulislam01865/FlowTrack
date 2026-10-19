# Order Redo Selected-Phase Restart Fix — 2026-08-25

## Requirement

When a user initiates a Redo and chooses the scope in Step 2, the NEW linked Redo Order must actually begin from the selected workflow stage.

- **Artwork + production redo** => the Redo Order starts at the workflow's Artwork phase.
- **Production-only redo** => the Redo Order starts at the workflow's Production phase.
- The original Order is not rewound or changed.
- Phases before the chosen restart phase are inherited/carried forward as completed.
- The chosen phase becomes the current active phase.
- The first required task in that chosen phase becomes actionable using the existing sequential task service.
- Future phase tasks remain Not Started/locked until normal workflow advancement.

## Files changed

### 1. `app/Services/OrderRedoService.php`

This is the main logic change.

`createRedo()` already resolved `artwork`/`production` from the modal scope. It now treats that resolved phase as authoritative and then calls:

```php
$this->initializeRedoWorkflowAtPhase($redoOrder, $phases, $restartPhase, $actor);
```

The new `initializeRedoWorkflowAtPhase()` method:

1. Marks generated tasks in phases before the selected restart phase as completed/carried forward.
2. Resets the selected phase to Not Started.
3. Leaves future phases Not Started and locked.
4. Makes the selected phase the Redo Order's `workflow_phase_id` and `started_from_phase_id`.
5. Creates/updates phase-history rows so the selected phase is the active history row.
6. Calls the existing `OrderTaskSequenceService` to unlock exactly the first required task in the selected phase.
7. Updates `next_action` to that first required task.
8. Recalculates status and progress after the selected phase is established.

The saved activity metadata now also includes the actual restart phase id, name, and sequence.

### 2. `app/Livewire/Jobs/Concerns/ManagesOrderRedo.php`

After the Redo is created, FlowTrack now opens the newly-created `-R1`/`-R2` Redo Order immediately instead of leaving the user on the original Order's Redo tab.

This makes the selected restart stage visible immediately in the Order Details header/stage UI.

### 3. `resources/views/components/jobs/order-detail/redo-panel.blade.php`

The `Workflow restart` row now prefers the Redo Order's actual current phase name. The existing scope-based Artwork/Production label remains as a fallback.

### 4. `tests/Feature/OrderRedoImplementationTest.php`

Static integration assertions were expanded to protect the selected-phase restart behavior.

## Result examples

### Artwork + production redo

If the source Order is already at QC/Dispatch and the user chooses Artwork + production:

```text
Original Order: remains at its existing stage

Redo Order -R1:
New Order / Intake       -> carried forward / complete
Artwork                  -> CURRENT / ACTIVE
Production               -> locked until Artwork completes
Receiving / QC / Dispatch -> locked
Invoice / Payment        -> locked
```

### Production-only redo

```text
Original Order: remains unchanged

Redo Order -R1:
New Order / Intake       -> carried forward / complete
Artwork                  -> carried forward / complete
Production               -> CURRENT / ACTIVE
Later phases             -> locked
```

## Database

No new migration is required for this fix. It uses the existing `flow_jobs`, `tasks`, `flow_job_phase_histories`, and `order_redos` structures.
