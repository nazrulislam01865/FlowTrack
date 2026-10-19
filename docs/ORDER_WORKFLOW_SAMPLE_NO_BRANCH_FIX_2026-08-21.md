# Order Workflow — No-Sample Branch / Phase Progression Fix

## Problem
After Client Artwork Approval, choosing **No** for "Is a Sample or Swatch Required?" left Artwork active and exposed **Sample Approval (when required)** as the next action instead of moving directly to Production.

## Root causes
1. The optional Sample Approval task used the initial status `Not Ready`, but the Order presenter treated any status other than `Not Started` as an activated conditional task.
2. After the required Client ERP / Approval task completed, the presenter fell back to the first incomplete task, including untouched optional tasks.
3. The no-sample decision did not explicitly mark the optional sample branch as skipped.
4. A skipped optional task with a configured document could still be interpreted as a missing-document blocker.
5. Automatic stage advance depended too strongly on the dedicated workflow id; compatibility/snapshot runtime Orders could therefore remain on the completed stage even though their prototype task set was present.

## Fix
- `ART_CLIENT_ERP_DECISION + confirm` now explicitly marks `ART_SAMPLE_APPROVAL` as `Skipped`, records `job.sample_not_required`, completes the Client ERP / Approval task, and lets the normal TaskService -> JobService lifecycle advance the Order.
- Optional tasks in `Not Ready`, `Not Started`, or `Locked` are treated as untouched/inactive.
- Untouched optional tasks are not selected as the next action and are not displayed as active conditional tasks.
- Skipped tasks do not create document blockers.
- Phase progress/completion excludes inactive/skipped conditional tasks.
- Order auto-advance also recognizes the seven-stage runtime from its already eager-loaded automation-key tasks, so stale workflow ids cannot strand a completed stage.

## Expected path
Client ERP / Approval -> Client Approved Artwork -> Sample required?

- **No** -> Sample Approval skipped -> Artwork completes -> Production activates.
- **Yes** -> Client decision waits -> Sample Approval activates -> upload approval -> Artwork completes -> Production activates.

No frontend JavaScript decides these transitions; the backend service lifecycle remains authoritative.
