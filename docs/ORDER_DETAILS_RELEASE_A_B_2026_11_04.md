# FlowTracker - Order Details Releases A and B

Date: 2026-11-04. Based on `Archive 3(20261104-163734).zip` and the supplied five-phase implementation plan.

## What this patch changes

**Release A (baseline and validation tooling)**

- Reuses the existing, opt-in `OrderBaselineRecorder`, `RequestPerformanceMonitor`, `order-baseline-report.py` and runtime feature flags; none of their production defaults are changed.
- Includes `ORDER_DETAILS_RELEASE_A_HISTORICAL_BASELINE.md`, generated from the archive's recorded `phase5_summary_on` run (74 diagnostic events, collected November 3). These are **historical before-optimization observations**, not results of this patch.
- Adds `scripts/order-release-ab-compare.py` for matched run/order/action comparisons. It refuses to mark missing or insufficiently sampled before/after groups as complete.
- Keeps the existing targeted-realtime setting untouched. Release A only validates this setting; turning it on is Release D after separate approval.

**Release B (read-path SQL reductions)**

1. `OrderWorkflowBindingService::publishedWorkflow()` reuses the `Workflow` model returned by the existing `ensureRuntimeMirror()` reconciliation, eliminating an immediately repeated Workflow SELECT; the mirror repair is **not** skipped.
2. `OrderWorkflowSection::loadWorkflowSection()` reads the initial `FlowJob` once for the phase-before-synchronization value and the auto-advance input; if synchronization actually updates the order, it refreshes the model first. No authorization or advancement rule changes.
3. Adds opt-in `FLOWTRACK_WORKFLOW_AUTO_ADVANCE_BLOCKER_FAST_PATH_ENABLED` (default **false**). After the existing permission-scoped `findVisibleBase()` read, one bounded `EXISTS` check detects a **known incomplete required current-phase task**. When one exists, the full auto-advance task/evidence graph is unnecessary: the exact existing `JobDetailPresenter::blockers()` would block the transition anyway. When there is no such task, the original full gate, evidence checks, hold checks and transaction-based phase completion execute exactly as before.
4. Includes `task_pack_id` in the already-eager-loaded phase selection needed for the conservative check; this adds **no extra query**.
5. Keeps standalone artwork evidence repair unchanged. Its no-change return path can still detect orphaned historical evidence. Suppressing it based solely on `syncSingleActiveOrder() === false` would be unsafe.

**No UI, route, task pack, phase, email, document, realtime-JavaScript, migration, authorization, or schema changes.** No additional background jobs, cache keys, or persistence tables.

## Safety properties of the opt-in blocker fast path

- The order is permission-scoped before the EXISTS query; hidden tasks are **not omitted** from the internal phase blocker check, as required for supplier delegates.
- Only required Task Pack items from the actual current phase pack and manual open tasks qualify. Optional, foreign-pack and archived generated tasks do not cause a shortcut.
- Both `completed_at` and `status` are checked. A known uncompleted required task blocks irrespective of document evidence. If uncertain, the original full gate runs.
- The authoritative `completePhase()` transaction, rechecks and locks are unchanged. The shortcut never calls `completePhase()`.
- A newly completed task invokes the existing workflow action / auto-advance hooks; normal browser visits still run the complete gate if no required blocker exists. Do not enable the shortcut until the concurrent-completion and supplier tests below pass.

## Staging procedure: Release A flag matrix

Use **one disposable staging DB snapshot per comparison**, same logged-in user, workspace, order IDs, published workflow definitions, cache mode and browser procedure. Do **not** compare a repaired/stale order to the same order after repair; restore data state first. `php artisan config:clear` or the deployment's approved `config:cache` refresh is required between settings changes.

1. Record `A0` with the existing three flags off: `FLOWTRACK_WORKFLOW_MAINTENANCE_BATCH_ENABLED=false`, `FLOWTRACK_WORKFLOW_SUMMARY_EVIDENCE_EXISTS_ENABLED=false`, `FLOWTRACK_ORDER_TARGETED_EVENTS_ENABLED=false`.
2. Restore snapshot; record `A1` with **maintenance batch only** on. Compare workflow repairs and healthy visits, assert tasks/assignments/comments/member identities unchanged.
3. Restore snapshot; record `A2` with **summary evidence EXISTS only** on. Verify completed/optional/conditional task visibility for admin and task-scoped supplier delegates.
4. Restore snapshot; record `A3` with **targeted events only** on, for diagnostics **not rollout**. Validate same-order events, unrelated-order silence, unknown-order fallback, role revocation, browser reconnect and supplier callbacks. Leave default off until Release D.
5. Restore snapshot; record `B0` (new patch deployed, blocker fast path OFF). This measures the two unconditional no-risk SELECT deduplications.
6. Restore snapshot; record `B1` (same patch, blocker fast path ON), with all other flags held constant to `B0`. Compare healthy orders with open required tasks and fully eligible phases separately. Run the negative/legacy scenarios as well.

Recommended instrumentation (for each run):

```dotenv
FLOWTRACK_ORDER_BASELINE_ENABLED=true
FLOWTRACK_ORDER_BASELINE_RUN_ID=AB_B0
# For B1 only, after restoring the exact same snapshot:
FLOWTRACK_WORKFLOW_AUTO_ADVANCE_BLOCKER_FAST_PATH_ENABLED=true
```

Run at least five cold and five warm opens **per order/case**. Capture initial GET, Products, Workflow, Attachments, Activity and key parent rerenders. Use the existing recorder's request and service span metrics (SQL count/time, PHP CPU, duration). Save the browser waterfall separately. Generate a report for each run:

```bash
python3 scripts/order-baseline-report.py \
  --input storage/logs/order-details-baseline.jsonl \
  --run-id AB_B0 --output storage/logs/order-release-ab-b0.md

python3 scripts/order-baseline-report.py \
  --input storage/logs/order-details-baseline.jsonl \
  --run-id AB_B1 --output storage/logs/order-release-ab-b1.md

python3 scripts/order-release-ab-compare.py \
  --input storage/logs/order-details-baseline.jsonl \
  --before AB_B0 --after AB_B1 \
  --output storage/logs/order-release-ab-comparison.md
```

The comparator requires **matching order IDs and request names** and at least five observations per side. It does not prove equivalent data state, correctness, or p95 improvement by itself: verify these with fixture logs and tests.

## Validation checklist

Run after installing Composer dependencies and connecting an isolated test database:

```bash
php artisan test --filter=OrderWorkflowReleaseABFastPathTest
php artisan test --filter=OrderWorkflowPhase4MaintenanceOptimizationTest
php artisan test --filter=OrderWorkflowPhase5SummaryExistsOptimizationTest
php artisan test --filter=OrderWorkflowSectionQueryDeduplicationPerformanceTest
php artisan test --filter=OrderWorkflowBatchSyncPerformanceTest
php artisan test --filter=SupplierTaskPhaseAdvanceAuthorityTest
php artisan test --filter=OrderWorkflowStageAdvanceReplayGuardTest
php artisan test --filter=OrderWorkflowSummaryReadModelPerformanceTest
php artisan test --filter=OrderWorkflowSampleBranchRegressionTest
php artisan test --filter=SupplierPurchaseOrderWorkflowTest
python3 -m unittest discover -s scripts/tests
```

Manual/integration acceptance before staging-flag sign-off:

- Healthy current-phase required task incomplete: no auto-advance, correct visible tasks and status.
- All required tasks complete, with or without document/link requirements: original auto-advance and evidence gate applies.
- Manual task, optional task, conditional branch, skipped task, missing task pack, wrong pack, soft-deleted old task.
- Workflow edited/published, old five-stage imports, missing generated task and detached artwork document/link: sync and repair still work.
- Repeated opens (no task, activity, member, phase history duplication), hold/release, completed/cancelled orders and permissions.
- Supplier action followed by auto-advance, two users finishing tasks simultaneously, active workflow publish during a visit.
- Compare SQL count, SQL time, PHP CPU and response p95 for each identical scenario; do not approve a change with worse correctness or important latency regressions.

## Deployment and rollback

- Replace only the files in the changed-files ZIP; keep `.env` and compiled `bootstrap/cache` out of the patch.
- Apply existing project config-refresh procedure (`php artisan optimize:clear` on local/staging); there are **no migrations** and **no frontend build changes**.
- Leave the new blocker flag `false` on production until staging tests and A/B comparisons are signed off. Enable it independently for staging measurement only.
- If the blocker fast path disagrees with the original gate, set `FLOWTRACK_WORKFLOW_AUTO_ADVANCE_BLOCKER_FAST_PATH_ENABLED=false` and refresh config. To undo the two unconditional SELECT deduplications, restore the original `OrderWorkflowBindingService.php` and `OrderWorkflowSection.php` from the pre-deploy backup.
- Existing maintenance, summary and realtime flags remain at whatever values your deployed environment explicitly configures; this release does not silently switch them on.

## What was verified in the source-only environment

- Historical `phase5_summary_on` report produced: 30 median SQL for initial GET and 90 for Workflow on order 333 (samples from archive, not a new benchmark).
- PHP syntax verification and Python diagnostic script tests may run without Composer.
- The uploaded ZIP contains no `vendor/autoload.php`, configured MySQL service or PHP SQLite extension. Therefore **no live Laravel tests, true DB flag A/B measurements, concurrency tests or runtime reduction claims can be verified here**. These are mandatory before marking Release A/B production-ready.
