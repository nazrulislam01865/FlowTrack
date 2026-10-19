# FlowTrack Database and Request Performance Guidelines

## Operational read rule
Every user-facing operational list must be intentionally bounded. Prefer `paginate()`/`simplePaginate()` for human navigation, `cursorPaginate()` for very large stable sequential lists, and `chunkById()`/`lazyById()` for background/bulk work.

A syntactic `->get()` is not automatically unsafe. Each occurrence must be classified by its effective bound and ownership. Phase 11 uses these categories: explicit limit/page window, ID-bounded scope, parent/relation-scoped detail read, setup/reference data, report read model, background batch, scoped domain read, or unsafe-unbounded operational hydration.

## Aggregates
Use SQL `count`, `sum`, `exists`, grouped aggregates or subqueries rather than hydrating collections solely to calculate totals. Select only columns needed by the screen when a read model is stable enough to do so safely.

## Eager loading
High-traffic queries should eager-load only relationships rendered by the response. Development and test environments enable Eloquent lazy-loading detection and log violations as `flowtrack.performance.lazy_loading`; production behavior is not changed by this diagnostic.

## Phase 11 budgets
The configured starting budgets are:
- standard authenticated server p95: 500 ms where practical;
- heavy report p95: 1000 ms, otherwise split/lazy/async;
- standard request query count review budget: 80;
- standard total query-time review budget: 400 ms;
- slow-query review threshold: 150 ms.

These are engineering review budgets, not guarantees independent of infrastructure or dataset size.

## Index verification
Phase 11 adds composite indexes for real Order, Task, Inquiry, Inquiry Task, Document, Notification, Activity, Master Data and Client filter/sort shapes. Verify them against the production-like database with:

```bash
php artisan flowtrack:performance:explain --user=<user-id>
```

Review the `EXPLAIN` key, rows and access type. Do not keep an index solely because it exists in the migration; it must correspond to a measured filter/sort/query shape.

## Runtime measurement
Enable the existing request performance monitor on a representative environment and capture p50/p95/p99, query count and total query time for the busiest screens. Compare the same routes, user scopes, filters and dataset before and after a query/index change.

Query/index changes should be isolated and reversible. If an optimization changes returned rows, permission scope, sort order or workflow behavior, revert it rather than accepting a semantic regression for speed.
