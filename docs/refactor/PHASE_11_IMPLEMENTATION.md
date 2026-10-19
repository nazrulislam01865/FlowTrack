# Phase 11 - Database, Query and Request Performance

## Query inventory
The current executable source contains 312 syntactic `->get()` occurrences, higher than the roadmap's older 279-count snapshot because features were added after that baseline. `quality/phase11-query-inventory.json` records every current occurrence with file, line, method, classification, rationale and source snippet.

No reviewed top-level operational list remains classified as unsafe unbounded hydration. Orders, Inquiries, My Work, Documents, Clients, Master Data and Notifications use pagination/bounded windows; remote selectors are explicitly capped.

## Index set
Phase 11 adds one isolated migration containing 12 genuinely missing composite indexes for current Order, Task, Inquiry, Inquiry Task, Inquiry Document and Client filter/sort patterns. Existing notification, activity, Master Data, task-assignee, document and client-manager indexes are intentionally reused instead of duplicated. No historical migration was edited; `quality/pre-phase11-migration-hashes.json` freezes all pre-Phase-11 migration hashes.

## Diagnostics
Development/testing enables non-throwing Eloquent lazy-loading detection. The existing `flowtrack:performance:explain` console command is the query-plan verification tool for a representative database.

## Governance
Earlier architecture gates were updated so future migrations are allowed while every pre-Phase-11 migration must remain byte-for-byte unchanged. `scripts/quality/phase11-performance.php` also requires the reviewed get-inventory to match executable source exactly; new or moved occurrences require explicit review/reclassification.

## Runtime verification requirement
Static/source acceptance can be executed in this archive. Actual p95/query-time benchmarks and MySQL EXPLAIN results require a dependency-complete environment and representative database. They must be captured before production release; this phase does not fabricate those runtime measurements.
