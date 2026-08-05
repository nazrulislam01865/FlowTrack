# Job Details Phase Task Permanent Repair

## Root cause

Job Details had two different sources for phase Tasks:

1. the authoritative Job Details API task set; and
2. the shared paginated frontend `tasks[]` collection used by Jobs, Board and My Work.

Older Jobs can also pre-date the rule that materializes every phase's Task Pack at Job creation. Board/My Work could appear complete because Task Pack configuration was already loaded and the UI synthesized planned rows, while Jobs -> Job Details could expose only the persisted current-phase Tasks.

The previous complete-snapshot change removed that synthesized fallback, exposing the incomplete legacy persisted data.

## Permanent fix

- Added `JobPhaseTaskMaterializer`.
- Job Details API reconciles all active, non-excluded, non-skipped workflow phases against their Task Packs before loading Tasks.
- The `/jobs/{job}/tasks` fallback endpoint performs the same reconciliation.
- Reconciliation is idempotent and only invokes `TaskPackActivator` for a phase when its persisted Task Pack item set is actually missing/mismatched.
- Silent legacy/detail repair does not emit duplicate future-phase task notifications.
- Job Details API continues to return `taskSet.complete = true` only after reconciliation and full Task loading.
- Job Details stores the returned complete Task set on the Job as a private `_detailTasks` snapshot.
- `flowtrackJobAllTasks()` uses that snapshot while Job Details is active instead of the shared paginated `tasks[]` array.
- Full Task mutation responses keep the private Job Details snapshot synchronized.
- Jobs, Board, My Work and direct Job URLs therefore render the same persisted phase/task set.

## New Jobs

`WorkflowEngine::initialize()` already materializes every non-skipped phase Task Pack during Job creation. The new materializer is primarily a compatibility/self-heal path for older or partially migrated Jobs.

## Incomplete-response guard

The frontend now refuses to render Job Details as a complete phase/task view unless the API response contains:

- `taskSet.complete = true`
- `taskSet.persisted = true`
- a Task count matching the returned Task array

If the first Job Details response does not satisfy that contract, the dedicated Job Tasks endpoint is fetched. If that is also incomplete, the partial set is not presented as complete.
