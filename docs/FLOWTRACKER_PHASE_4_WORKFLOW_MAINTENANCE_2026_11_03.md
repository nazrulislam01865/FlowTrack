# FlowTracker Phase 4 — Guarded Workflow Reconciliation Optimization

Date: 2026-11-03  
Source: `Archive 3(20261103-052306).zip` (latest uploaded project), and the six-phase implementation playbook.

## Why this patch is deliberately conservative

Phase 3's local diagnostic run showed a 779.16 ms / 654-query `loadWorkflowSection` for Order 58, including 655.83 ms / 588 queries in `workflow_sync`. A save-document action separately had 478 queries. Reconciliation legitimately repairs historical/stale workflow state and artwork; simply removing it from the read path would risk broken progress, missing attachments and supplier state.

**This release addresses redundant queries within that existing reconciliation path.** The feature flag `FLOWTRACK_WORKFLOW_MAINTENANCE_BATCH_ENABLED` defaults to `false`. It does **not** introduce a schema migration, a new queue job, a background repair task, a version token that could silently go stale, new SQL in Livewire render, or changes to permissions, existing task transitions, automatic advancement or artwork recovery. The current strict `runtimeMatchesPublishedDefinition` fast path remains authoritative; it prevents the full transaction when runtime binding is already healthy.

### Changes when the flag is enabled

1. **Generated Task Pack tasks:** one existing-task fetch per phase, reused for task updates. Only *missing* tasks call the existing `Task::firstOrCreate`, retaining concurrency and task-number guards. The previous separate existence-ID query is reused from this result, not duplicated.
2. **Creation comments:** one `FlowTaskComment` lookup per phase for existing generated tasks. `firstOrCreate` runs only where the standard creation comment is missing. All creation comments remain intact.
3. **Order membership:** one membership lookup per phase only when assignees are possible. Existing members do not trigger repeated `firstOrCreate`. Missing members still use the old guarded creation path, including the same permissions/pivot attributes.
4. **Task flag master data:** the existing `Cache::remember` result for `order_task_status`, `order_task_flag` and `order_flag` is reused **only inside** the original Workflow repair transaction. Every caller still receives independent `MasterRecord` instances. The snapshot is discarded in `finally`, including on error. The existing Master Data `save`, `delete` and toggle invalidation also invalidates the request-local snapshot. Normal master-data views and other operations use the unchanged cache path.

The new SQL reads are bounded by phase, not generated task count: one task prefetch + zero/one comment prefetch + zero/one membership prefetch. These replace per-task SELECTs rather than adding a new N+1.

### Explicitly unchanged

- `OrderWorkflowSection::loadWorkflowSection()` still runs the existing binding safety check; it still repairs artwork when needed and executes `AutoAdvanceOrder` before actions become available.
- `OrderWorkflowBindingService` still locks the Order row and uses its original transaction, full repair fallback and post-repair summary invalidation.
- The original Task Pack assignee resolution, generated-task fields, status/flag calculation, comments, Order member privileges, supplier assignment, notifications, history, and artwork version recovery paths are unchanged.
- The `Task::firstOrCreate`, `FlowTaskComment::firstOrCreate` and `FlowJobMember::firstOrCreate` writes are retained for records that do not exist.
- Phase 1/2/3 flags and existing JS/assets are unchanged. No UI redesign.

## Known limitations / not claimed

This is a **safe Phase 4 optimization slice**, not a validated complete migration to mutation-driven Workflow reconciliation. It does not yet add published-definition/order-binding version tokens, a reconciliation queue, an operator repair command or a scheduled audit. Moving all reconciliation and auto-advance off page load would require an exhaustive audit of mutation pathways and supplier callbacks, staging fixtures and concurrency validation. The current compatibility repairs remain on demand, intentionally. The 478-query document-save operation is **not** optimized here; its independent causes should be traced before attempting SQL changes.

Measured reductions are **not yet established**; Composer dependencies and a representative database were unavailable in the packaging environment. Evaluate on a private local/staging database before production.

## Local install / testing

Back up the existing project and local DB. Apply the **changed-files ZIP** to the existing checkout (or use the full ZIP while retaining local `.env`, `vendor`, runtime files and DB). Then:

```bash
php artisan optimize:clear
php -l app/Services/LegacyJobService.php
php -l app/Services/OrderWorkflowBindingService.php
php -l app/Services/MasterDataService.php
php artisan test --filter=OrderWorkflowPhase4MaintenanceOptimizationTest
php artisan test --filter=OrderWorkflowBatchSyncPerformanceTest
php artisan test --filter=OrderWorkflowSectionQueryDeduplicationPerformanceTest
php artisan test --filter=OrderWorkflowStageAdvanceReplayGuardTest
php artisan test --filter=OrderWorkflowSummaryReadModelPerformanceTest
php artisan test --filter=OrderWorkflowSampleBranchRegressionTest
php artisan test --filter=OrderWorkflowLegacyItemModalRegressionTest
php artisan test --filter=SupplierPurchaseOrderWorkflowTest
```

Tests against production-like workflow fixtures are essential; source-contract/unit tests alone cannot verify task lifecycle safety.

### Controlled same-order A/B measurement

Use your **local or isolated staging** database and the same Order 58, task data, browser and SQL cache configuration. Do not compare an already-repaired warm Order with a stale cold Order: that would conceal differences or create false improvements.

**Before: `FLOWTRACK_WORKFLOW_MAINTENANCE_BATCH_ENABLED=false`.** Enable the existing Order baseline diagnostics with a new run ID (`phase4_before`) and run the same disposable stale/workflow-repair fixture, document action and warm-open fixture. Generate report:

```bash
python3 scripts/order-baseline-report.py \
 --input storage/logs/order-details-baseline.jsonl \
 --run-id phase4_before \
 --output storage/logs/order-baseline-phase4-before.md
```

**After: `FLOWTRACK_WORKFLOW_MAINTENANCE_BATCH_ENABLED=true`.** Clear config, restore **the exact same database fixture snapshot** used in the before run, use run ID `phase4_after` and repeat the same steps. Generate a corresponding `phase4_after` report. Record per-request query count, DB time, PHP CPU and duration, not just the average. Repeat at least five times using identical stale and healthy fixtures.

### Functional and concurrency acceptance

- New Order generation and Task Pack edits; missing/duplicate Task Pack mirrors and legacy five-stage imports.
- Healthy Order open (no extra writes); stale Order open (all generated tasks + history repaired); repeated open (no duplicate tasks/comments/members).
- Assigned, department-fallback and manually reassigned tasks; assignee changes, required docs, due dates, flags and sequence ordering.
- Completed, on-hold, cancelled, historical and restricted-viewer Orders.
- Retired artwork documents/links and unrelated general attachments with null `task_id`.
- Auto-advance and task completion without re-opening Order Details; supplier task callbacks and acknowledgments.
- Two simultaneous reconciliation requests and a concurrent Task Pack publish: task numbers and histories must remain unique; no lost document links.
- Master Data flags changed during a repair batch: invalidated lists must be reloaded, never cached after a change.

**Do not declare the Phase 4 playbook gate complete** unless healthy Order Workflow load makes no unnecessary maintenance writes, stale historical state still repairs, no N+1 appears, SQL/CPU improve with matching fixtures, and role/supplier tests pass.

## Immediate rollback

```dotenv
FLOWTRACK_WORKFLOW_MAINTENANCE_BATCH_ENABLED=false
```

```bash
php artisan config:clear
```

No migration or Redis key deletion is needed. The previous per-task read path returns immediately. If a task or document result differs, disable the flag, preserve the before/after reports and investigate before proceeding.
