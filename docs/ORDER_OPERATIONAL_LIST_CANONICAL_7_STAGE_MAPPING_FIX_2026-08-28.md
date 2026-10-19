# Order operational lists: canonical seven-stage mapping fix — 2026-08-28

## Problem

The current Order workflow contract has seven visible stages:

1. New Order
2. Artwork
3. Production
4. QC
5. Shipment
6. Billing
7. Payment

Some existing Orders and Tasks were created while earlier workflow snapshots were active. Their stored `workflow_phase_id` can still point to historical `workflow_phases` rows such as `Order Intake`, `QC & Dispatch`, or `Invoice & Payment`.

Order Details already works from the current Order runtime, but three operational read models were still exposing the raw historical phase label:

- Orders list
- My Tasks
- All Tasks

That made the UI appear to be using an old workflow even though Workflow Setup itself had not reverted.

## Fix

Added `App\Support\OrderStageResolver` as the shared presentation contract for Order operational stages.

Resolution precedence is:

1. Task automation key (`NEW_`, `ART_`, `PROD_`, `QC_`, `SHIP_`, `BILL_`, `PAY_`) when available.
2. Known current or legacy phase name aliases.
3. Valid seven-stage sequence as a final fallback.

Legacy aliases are folded into the current runtime without deleting historical rows:

- `Order Intake` -> `New Order`
- `QC & Dispatch` -> `QC` or `Shipment` when operational status proves shipment/dispatch has been reached
- `Invoice & Payment` -> `Billing` or `Payment` when operational status proves payment has been reached

## Updated read models

### Orders list

`OrderListPrototypeService` now canonicalizes:

- current-stage labels
- stage sequence used by row behavior
- stage card counts
- stage filter phase IDs, including historical/snapshot aliases

When a next active task exists, its automation key is used as the strongest stage signal.

### My Tasks

`MyWorkService` now canonicalizes:

- Order group stage
- task phase label
- stage cards and counts
- stage filter options
- historical/snapshot source IDs used by stage filtering

The seven stage options now come directly from `OrderWorkflowSetupService::fixedStages()`.

### All Tasks

`BoardTaskPackService` now uses the same resolver and delegates phase options/filter alias handling to `MyWorkService`, preventing the two task pages from drifting apart.

## Data safety

This is intentionally a read-model/presentation repair. Historical workflow phase rows are retained for audit and snapshot safety. The list pages do not rewrite Orders while loading.

The existing maintenance command can still be run after deployment to rebind eligible active Orders to the latest Order workflow where appropriate:

```bash
php artisan flowtrack:sync-order-workflow
```

Correct stage display no longer depends on that command. `OrderWorkflowBindingService` now also uses the same shared resolver, so a physical rebind cannot apply a different legacy-stage interpretation from the list pages.

## Verification

- PHP syntax checks pass for all changed PHP files.
- Unit coverage added for legacy-to-canonical stage resolution and automation-key precedence.
- Feature-source regression coverage verifies that Orders, My Tasks, and All Tasks all use the canonical resolver.
- Full Laravel test execution requires the Composer `vendor` directory, which is intentionally not included in the supplied project archive.
