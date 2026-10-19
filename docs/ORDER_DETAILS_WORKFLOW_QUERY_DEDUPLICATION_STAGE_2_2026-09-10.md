# Order Details Workflow query deduplication — Stage 2 (2026-09-10)

## Evidence

Local instrumentation for Order `327` recorded:

- `jobs.order-workflow-section::loadWorkflowSection`: **272.92 ms**
- **109 SQL queries**
- **141.36 ms SQL time**
- **131.56 ms non-DB time**
- repeated Workflow Setup/Task Pack queries in the same request, including repeated active workflow checks, task packs, task-pack items and master records.

Products, Attachments and Activity were each below 40 ms in the same run, so this pass is intentionally limited to Workflow loading.

## Changes

1. `OrderWorkflowSetupService` and `OrderWorkflowBindingService` are request-scoped.
2. Active Order workflow existence checks are memoized for the current Laravel request only.
3. The binding path loads and validates the published phase/Task Pack graph once, then reuses it for strict runtime matching.
4. The initial Workflow render reuses that same graph instead of querying it again.
5. Active Orders no longer eager-load the legacy `Workflow::phases.taskPack.items` graph immediately before replacing it with published WorkflowTemplate phases.
6. Historical/completed/inactive Order rendering retains the original legacy relation path.
7. `isReadyForOrderCreation()` still uses the same seven-stage names, sequence, Task Pack presence and automation-key ordering rules. The shared validator is reused rather than reimplemented.
8. `runtimeMatchesPublishedDefinition()` and the existing full `syncOrder()` repair fallback are unchanged.

All memoization is request-scoped. A new request starts with empty caches, so Workflow Setup and Task Pack changes are not hidden by a long-lived application cache.

## No behavior changes intended

- no task completion rule changes
- no required/optional task changes
- no hold/unhold changes
- no phase advancement changes
- no authorization changes
- no database schema changes
- no UI/CSS/JS changes

## Validation in supplied archive

`php -l` passes for all changed PHP files and the new regression test. The archive has no `vendor/`, so PHPUnit cannot be booted in this sandbox.

Run locally:

```bash
php artisan optimize:clear
php artisan test --filter=OrderWorkflowSectionQueryDeduplicationPerformanceTest
php artisan test --filter=OrderDetailsFullIsolationPerformanceTest
php artisan test --filter=OrderDetailProgressiveLoadingPerformanceTest
php artisan test --filter=OrderWorkflowBatchSyncPerformanceTest
php artisan test --filter=OrderWorkflowPhaseCompletionColorTest
php artisan test --filter=OrderWorkflowSampleBranchRegressionTest
php artisan test --filter=OrderOverviewSummaryRealtimeRegressionTest
```

Then reload the same Order once and compare the `loadWorkflowSection` log to the baseline **109 queries / 272.92 ms**.
