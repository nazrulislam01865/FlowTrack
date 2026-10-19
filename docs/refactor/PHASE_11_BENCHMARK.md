# Phase 11 Benchmark Record

## Source inventory
- Roadmap historical `->get()` baseline: 279 occurrences at the source-review date.
- Phase 9 executable static architecture metric: 309 source lines containing `->get()`.
- Phase 11 exact syntactic inventory: 312 occurrences across 60 application PHP files.
- Reviewed unsafe-unbounded operational list occurrences after classification: 0.

The counts differ because the roadmap predates later features and because the exact inventory counts multiple calls on the same source line while older architecture metrics count source lines.

## High-traffic bounds confirmed in source
- Orders list: paginated read model with selected columns/eager loading.
- Inquiry list: paginator with an enforced maximum page size.
- My Work: parent Orders are paginated; task hydration is restricted to IDs on the current page.
- Document Archive: union is paginated; model hydration is restricted to current-page IDs.
- Clients, Master Data and Notifications: paginated.
- Remote Filter options: capped page size and selected-ID count.

## Index implementation
12 named composite indexes are introduced in the Phase 11 migration after checking the existing migration history for equivalent indexes. They target missing Order/Task ownership/phase/due-date shapes, Inquiry/task workspace/owner/client/assignee shapes, Inquiry Document updated-ordering and Client archived-state filtering. Existing notification, activity, Master Data, document and client-manager indexes are reused rather than duplicated.

## Runtime benchmark status
This sandbox does not contain the project's Composer dependencies or a representative MySQL dataset, so server p95, query totals and real `EXPLAIN` plans have not been measured here. The implementation therefore does not claim those runtime values as passed.

Before deployment, run the application against a production-like dataset, enable the existing request-performance monitor, exercise the busiest screens, and run:

```bash
php artisan flowtrack:performance:explain --user=<representative-user-id>
```

Record the query-plan key/access type/rows and the request p50/p95/p99, query count and total query time. Revert an index/query change if it changes result semantics or performs worse on the representative workload.
