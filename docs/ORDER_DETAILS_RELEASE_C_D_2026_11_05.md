# FlowTracker Order Details - Releases C and D

Scope: implement Phase 3 (materialized summary reuse) and Phase 4 (targeted realtime correctness) against `Archive 4(20261104-180018).zip` without changing any Blade view, workflow rule, task action, database schema, route, or queue.

## Release C - summary reads

**Existing behavior retained:** The Order Details parent already calls `OrderWorkflowSummaryService::forViewer()` only on its overview tab and uses the existing materialized `order_workflow_summaries` row for users allowed to see the shared summary. The service still rebuilds stale rows and calculates permission-scoped summaries for restricted viewers. Existing invalidation paths for task lifecycle, task links, documents, workflow/task-pack changes, and holds remain unchanged.

Changes:

1. `OrderRedoService::summaryContext()` now obtains the total Redo count and non-null redo-order count in one aggregate SELECT (`COUNT(*)` and `COUNT(redo_order_id)`). Previously these were two database count queries. Incoming redo lookup, latest display record, permissions, banners and tab data are unchanged.
2. `OrderWorkflowSummaryService::toArray()` uses the name from the already-eager-loaded owner, coordinator, creator, cancelledBy, or member user if that user is the next-task assignee. Otherwise it retains the original authoritative `users` name lookup. It does not lazy-load any relationship or store a name in the shared summary.
3. `AppServiceProvider` registers `OrderWorkflowSummaryService` as request-scoped, like existing scoped workflow services. Its existing `tableReady()` memoization can thus be reused within one request; there is no cross-request/worker cache.

No new data stores, cache keys, SQL queries, or invalidation writes were introduced. The summary remains an output-only read model. No claims of measured query reduction until local benchmarks are run.

## Release D - targeted refresh safety

The existing `FLOWTRACK_ORDER_TARGETED_EVENTS_ENABLED` flag remains **OFF by default**, and existing JS event transport, workspace fallback, notification badge sync, Livewire listeners and permission-scoped queries remain in place.

Changes in `resources/js/features/order-realtime.js`:

- `FlowJobMember:*` on the **active** order refreshes the summary and **all ready sections**, because a member/permission change may alter access to data already displayed by any child.
- `FlowJob:*` on the **active** order also refreshes all ready sections; Order fields such as owner affect child product data, not just the header and Workflow.
- Different-order events continue to trigger zero Order Details refreshes.
- Unknown/missing order IDs continue to fall back to the original broad workspace routing. Unknown scoped subjects refresh all ready sections. Unloaded sections remain lazy. The existing active-order and workspace-ID checks are unchanged.

Existing tests in `tests/JavaScript/order-phase3-routing.mjs` now cover these cases. No server event payloads, observers, database calls or Livewire component listeners were changed.

## Checks performed on the packaged source

- `php -l` on all changed/new PHP files: pass.
- `node scripts/quality/js-syntax.mjs`: pass.
- `node tests/JavaScript/order-phase3-routing.mjs`: pass.
- `node tests/JavaScript/order-phase3-transport.mjs`: pass.
- `node tests/JavaScript/phase13-unit.mjs`: pass.
- `node tests/JavaScript/notification-sync-dedupe.mjs`: pass.
- `node tests/JavaScript/persisted-counters-shared-request.mjs`: pass.
- ZIP integrity and original-archive file scope: verified.

**Not verified in this container:** Laravel/PHPUnit integration tests, local MySQL performance, real browser permission changes, and compiled Vite output. The supplied archive has no `vendor/` or `node_modules/`. Therefore do not describe Release C/D as production-certified based on syntax and JS tests alone.

## Local installation / tests

Apply changed-files ZIP from the project root (or use the full-source ZIP), preserving relative paths. With dependencies available:

```bash
php artisan optimize:clear
php artisan test --filter=OrderDetailsReleaseCDSummaryOptimizationTest
php artisan test --filter=OrderWorkflowSummaryReadModelPerformanceTest
php artisan test --filter=OrderOverviewSummaryRealtimeRegressionTest
php artisan test --filter=OrderPhase3RealtimeContractTest
php artisan test --filter=OrderDetailsFullIsolationPerformanceTest
php artisan test --filter=OrderRedoImplementationTest
php artisan test --filter=OrderWorkflowReleaseABFastPathTest
node tests/JavaScript/order-phase3-routing.mjs
node tests/JavaScript/order-phase3-transport.mjs
npm ci
npm run build
```

**Vite rebuild is mandatory before enabling Release D.** `public/build` is retained exactly as in the original ZIP and is NOT an updated JS build. When using the changed-files ZIP, run `npm ci` (if necessary) and `npm run build` in the local source checkout, then deploy matching `public/build` and `manifest.json` together.

For local / staging realtime validation only:

```dotenv
FLOWTRACK_ORDER_TARGETED_EVENTS_ENABLED=true
```

Then `php artisan optimize:clear`, hard-refresh, and test the following using two authorized users and actual workspace events:

1. Open Order A. Change Order B: no Order A targeted component requests. Check both private notification and workspace events.
2. Update Order A's owner or membership: all already-ready detail components re-query with server-side permission scopes.
3. Task / document / product / activity updates: only mapped sections refresh; unseen sections remain lazy.
4. Remove a user's Order access while they have Order A open: no protected child data must remain usable; revalidate authorization.
5. Unknown-order events, coalesced updates, browser reconnect and version drift: the existing broad fallback must converge to fresh data.
6. Check task actions, stage advancement, workflow/redo/hold UI, and all role scopes; confirm no unauthorized request reveals hidden tasks.

For performance comparison, use the existing order-baseline recorder to capture at least five matching reads for the **same** order, user, status, and warm/cold cache state, before and after Release C. Compare initial GET SQL count/DB time/p95. For Release D, use browser Network to compare **number of Livewire POSTs** and backend SQL calls on identical Order A/Order B events with the flag OFF and ON. No live performance results are supplied with this patch.

## Rollback

- **D**: Set `FLOWTRACK_ORDER_TARGETED_EVENTS_ENABLED=false`; run `php artisan optimize:clear` and reload the browser. Legacy broad dispatch remains available. Ensure Vite asset/code revision compatibility.
- **C**: Restore the original three changed PHP files (`OrderRedoService.php`, `OrderWorkflowSummaryService.php`, `AppServiceProvider.php`) from your pre-deploy backup, then run `php artisan optimize:clear`. No migration or data rollback is required.

Release E (production performance sign-off) is out of scope.
