# Order Performance Stage 5 + 6 — 2026-09-10

## Evidence used

The measured local trace showed `flowtrack.performance.lazy_loading` for `App\Models\Task::assignee` while opening Order Details, and the isolated Workflow request remained around 90–96 queries. Repeated queries included the Order base graph, workflow-active checks, phase/task-pack reads and a shipment existence check.

## Stage 5

`OrderDetailViewService::buildSummary()` now builds the header team only from the owner, coordinator and `members.user` relations already loaded by `findVisibleBase()`. The shared `BoardPresenter::team()` was deliberately left unchanged for richer screens. Workflow/task assignment paths mirror task assignees into `FlowJobMember`, so the lightweight Order shell no longer touches `Task::assignee` or triggers hidden presenter queries.

## Stage 6

- `syncSingleActiveOrder()`'s request-scoped published phase graph can be reused by auto-advance without forcing that heavier graph on unrelated callers.
- Auto-advance returns the already-authorized/hydrated Order it used. The first Workflow render reuses that model in the same Livewire request, avoiding a duplicate base Order hydration and scalar phase query. The private handoff is not persisted between requests.
- Current-phase Task Pack metadata uses `loadMissing()` so the graph loaded by binding is not queried again.
- The normal Workflow render checks the already-loaded shipment collection instead of issuing a preflight `shipments()->exists()` query. Missing legacy shipments still self-heal and reload.

No workflow progression, task completion, hold/unhold, authorization, Task Pack synchronization, database schema, or UI design rules were changed.

## Verification target

Re-run the same Order 326 cold test. The prior Workflow baseline was 96 queries / about 247–286 ms. Stage 5 should remove the `Task::assignee` lazy-loading warnings from the initial `jobs.index` request. Stage 6 should reduce Workflow query count without changing rendered behavior.
