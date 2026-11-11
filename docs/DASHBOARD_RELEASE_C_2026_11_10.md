# FlowTracker Dashboard Release C — scoped realtime and cache optimization

## Scope

This release builds on A (shell + essential overview) and B (isolated visible/lazy panels). No changes were made to business formulas, seven-stage mappings, dashboard wording, role scopes, or task/order workflows.

**What updates:** An existing workspace Reverb event contains subject/action identifiers, not old/new aggregate KPI values. The browser therefore **cannot safely adjust numerical values from the event payload itself**. It refreshes only the hydrated, affected Livewire panel(s), which re-read current authorized data. Livewire morphing updates the changed elements, preserving other panels and all local tab/page state. Unloaded panels are not mounted or queried. Unknown or version-only events use conservative reconciliation to avoid stale results.

### Realtime routing contracts

- Task/progress/assignment: overview, selected Priority, Attention, Distribution, Team and Recent Activity (if mounted).
- Order/phase: overview, Priority, Attention, Distribution, Team, Portfolio and Activity (if mounted).
- Inquiry: dependent overview/operational/distribution/portfolio/activity panels.
- Master Data changes: Catalogue and overview active-products KPI.
- Activity-only: Recent Activity.
- User mentions/notification state: recipient Mentions, plus Activity for new comments.
- Unknown or authorization changes: all **already mounted** panels, each rechecking access permissions.

The dashboard-specific refresh handler suppresses global events **only while the feature-flagged progressive dashboard is present**. Other pages retain their existing global refresh and order-targeted event handling. Existing workspace version polling remains the fallback for missed broadcasts; no new polling interval is introduced.

### Cache and query boundaries

- Existing `DashboardReadModelCache` remains the only dashboard read-model cache.
- In progressive mode, `catalogue-readiness` no longer keys against unrelated workspace Task/Order updates; a version key increments when Master Data or relevant authorization changes. Unknown reasons conservatively invalidate the catalogue. Other metrics retain existing workspace-version invalidation semantics.
- Priority tab and pagination continue to read only their owning component. Ranking is unchanged (no premature SQL LIMIT that could alter eligible candidates).
- No new SQL in the initial shell. No new database migration, indexes, or queue workers.
- Existing `RequestPerformanceMonitor` offers SQL fingerprints/queries with `performance.query_fingerprints` enabled in **staging** only. Avoid logging SQL bindings, user data or raw sensitive values.

## Deployment and validation

Set `FLOWTRACK_DASHBOARD_PROGRESSIVE_LOADING_ENABLED=true` and run:

```sh
cd /Applications/XAMPP/xamppfiles/htdocs/laravel/FlowTracker
php artisan optimize:clear
php artisan queue:restart
php artisan test --filter=DashboardReleaseCRealtimeScopeTest
php artisan test --filter=DashboardReleaseBPanelIsolationTest
php artisan test --filter=DashboardReleaseAProgressiveLoadingTest
php artisan test --filter=DashboardRealtimeConsistencyTest
node tests/JavaScript/dashboard-release-c.mjs
```

Open Dashboard with browser DevTools Network and record: (1) initial shell and summary, (2) Task status update, (3) only Recent Activity update, (4) catalogue change, (5) Priority Orders-to-Tasks click, (6) filters changed while a panel request is pending. Confirm no **unrelated dashboard** Livewire POSTs, no stale values, no forced offscreen panel loads, and unchanged navigation/permissions. Capture first-shell and panel p50/p75/p95, sampled query count/SQL time, cache hits, CPU, and 1/5/10/20-user staging comparison before declaring full performance acceptance. No live database benchmark is included in this package.

## Rollback

Set `FLOWTRACK_DASHBOARD_PROGRESSIVE_LOADING_ENABLED=false` and `php artisan optimize:clear`. Legacy deferred `dashboard.index` and the all-section reader remain intact. Clear browser cache if the Vite manifest remains stale.
