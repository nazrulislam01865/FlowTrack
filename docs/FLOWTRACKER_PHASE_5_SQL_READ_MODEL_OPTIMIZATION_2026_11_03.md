# FlowTracker — Phase 5 SQL and read-model optimization (candidate)

Date: 2026-11-03. Source: `Archive 4(20261103-061128).zip`.

## Scope and measured evidence

The post-Phase-4 local captures reported healthy Workflow loads with 82–87 SQL queries, and healthy `workflow_sync` spans with 16 queries for Orders 332/333. A previous repair path used 239 synchronization queries for Order 332. Different repair states **cannot** be treated as a controlled feature-on/off speedup.

The Phase 5 playbook also asks for request-scoped SQL fingerprint identification and EXPLAIN on a production-like *staging* database before introducing indexes. Those plans are unavailable in this source-only environment, so **no indexes or migrations were added**.

## Implemented, feature-flagged change

`FLOWTRACK_WORKFLOW_SUMMARY_EVIDENCE_EXISTS_ENABLED=false` (default): original summary build, eagerly loads each selected Task's `documents` and `links`, plus the `assignee` relation.

When **true**, `OrderWorkflowSummaryService::build()`:

1. Keeps the same `Task::query()` Order ID/phase constraints and `AccessControlService::applyTaskScope()` for restricted viewers.
2. Uses Eloquent `withExists(['documents', 'links' => ...])` projections. The `documents_exists` and `links_exists` flags are obtained in the existing Task SELECT; no per-Task SELECT is introduced.
3. Avoids hydrating full document/link collections *solely for evidence presence*. `OrderDetailPresenter::isConditionalTaskActivated()` accepts the EXISTS flags when the respective relation was not loaded; already-loaded relations continue to take precedence.
4. Avoids the assignee eager-load in the shared-materialized refresh (`$viewer === null`), because that rebuild does not store the assignee *name*. Restricted-viewer calculation still loads the assignee relation; shared summary display continues to resolve the actual next-task assignee in `toArray()`.

The change applies to **Workflow summary rebuilds**, not to Workflow task-row rendering: the latter still loads real documents/links needed for the UI. Phase 4 synchronization, supplier operations, Task Pack publication, artwork repair, and automatic advancement are unchanged.

### Expected query effect (hypothesis, not measured)

For each summary `build()` on the optimized route, two original eager-load SELECTs are removed: Documents and Task Links. Shared materialized refresh also avoids an Assignee SELECT. The replacement uses two indexed EXISTS *subqueries in the main Task SELECT*, which may be cheaper for modest Task counts but must be benchmarked for large Task Packs. Existing migrations include indexes on `documents.task_id` and `task_links.task_id`. SQL round-trips fall, but query-planner cost and DB CPU are not yet verified. Normal reads served from a fresh materialized summary are intentionally unchanged and should not be presented as a measured speedup.

## Modified source files

- `config/flowtrack.php`: default-off rollback flag.
- `app/Services/Orders/OrderWorkflowSummaryService.php`: opt-in relation preparation and EXISTS projections, shared-summary assignee skip.
- `app/Support/OrderDetailPresenter.php`: conditionally consume EXISTS attributes only when corresponding collection is not loaded, preserving original status and skipped-task precedence.
- `tests/Feature/OrderWorkflowPhase5SummaryExistsOptimizationTest.php`: conditional branch evidence, loaded-relationship precedence, skipped task, nextTask parity and source guard tests.

There are **no schema changes, new background jobs, new cache keys, new queues, changed business rules or UI changes**.

## Local test commands

```bash
php artisan optimize:clear
php artisan test --filter=OrderWorkflowPhase5SummaryExistsOptimizationTest
php artisan test --filter=OrderOptionalTaskVisibilityAndSequencingTest
php artisan test --filter=OrderWorkflowSummaryReadModelPerformanceTest
php artisan test --filter=OrderWorkflowPhase4MaintenanceOptimizationTest
php artisan test --filter=OrderWorkflowSampleBranchRegressionTest
php artisan test --filter=SupplierPurchaseOrderWorkflowTest
```

Then test restricted task-scoped and administrator/creator views side-by-side (same allowed Task IDs, task counts, next action and assignee). Test completed, on-hold, historical, missing documents, and a conditional sample branch backed by *either* file or link.

### Controlled staging A/B

1. On a disposable snapshot, enable baseline instrumentation and use a unique run ID `phase5_summary_off`; keep the Phase 5 flag **false**.
2. Trigger **the same** summary rebuild or restricted-user view for a representative small/medium/large Order and collect at least five runs. Do not compare a stale-summary refresh against a hot materialized read.
3. Restore the **same** snapshot/cache state. Set `FLOWTRACK_WORKFLOW_SUMMARY_EVIDENCE_EXISTS_ENABLED=true`, run ID `phase5_summary_on`, and repeat the identical actions/role.
4. Generate separate reports with `python3 scripts/order-baseline-report.py --input storage/logs/order-details-baseline.jsonl --run-id ... --output ...`; compare query count, DB ms, PHP CPU, end-to-end latency and failed actions. A lower query count alone is insufficient.
5. Capture staging SQL text *without bindings* for the top fingerprint hashes and run read-only `EXPLAIN` using the staging DB. Confirm both EXISTS projections use `documents.task_id` and `task_links.task_id` indexes before approving broader deployment.
6. Verify two-user simultaneous view, update while summary stale, missing summary, supplier transition and historical attachment recovery. Do not enable on production without these checks.

## Rollback

Set:

```dotenv
FLOWTRACK_WORKFLOW_SUMMARY_EVIDENCE_EXISTS_ENABLED=false
```

Then clear config cache according to deployment procedure (`php artisan config:clear` locally) and restart long-lived workers/servers where necessary. The original eager-loading code remains available. No database rollback is necessary.

## Validation status

PHP syntax and static guards can run without Composer dependencies. The uploaded source archive does **not** contain `vendor/` or a configured staging database. Consequently, PHPUnit, request-level measurements, permissions matrix, concurrency checks and SQL EXPLAIN **must be executed in the user's local/staging environment**. This implementation is a controlled Phase 5 candidate, not a claim that all Phase 5 acceptance gates have passed.
