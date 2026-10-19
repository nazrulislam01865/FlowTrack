# FlowTracker Order Details - Phase 1 measured findings (October 15, 2026)

## Scope and integrity

The uploaded local `order-details-baseline.jsonl` contains 27 events: 22 `initial_get` records and five `supplier_pending` markers. All 22 initial GET requests returned HTTP 200 and identify authenticated user ID 58. The original order sampler was run for user ID 1, so that sampling authorization context was not identical. Order sizes were also very similar (19/19/20 tasks, 1/1/4 documents and one product each). The provided report is therefore an **incomplete baseline**, not a production SLA confirmation.

The separate local Laravel performance log captures 16 `jobs.order-workflow-section::loadWorkflowSection` calls that are absent from the original structured JSONL baseline. The collector's path-only check did not capture this site's update URL. The associated opt-in compatibility patch recognizes the `default-livewire.update` route name instead.

## Initial GET server timings (original JSONL)

| Internal order ID | Samples | Request P50 (ms) | Request P95 (ms) | Queries P50 | DB P50 (ms) | PHP peak memory P95 (MB) |
|---|---:|---:|---:|---:|---:|---:|
| 83 | 3 | 64.74 | 110.62 | 25 | 27.06 | 4.00 |
| 264 | 6 | 59.93 | 78.95 | 25 | 22.82 | 4.00 |
| 329 | 5 | 65.24 | 117.98 | 29 | 28.93 | 5.60 |

These timings start within application middleware and do not measure complete browser visual readiness, network transfer, or initial PHP bootstrap.

## Workflow section bottleneck (from laravel.log)

Of 16 measured Workflow section loads, 12 had 81-89 queries and a median request duration of 146.12 ms. Four had 603-618 queries and a median of 840.62 ms (range 740.80 to 909.50 ms); the large-query cases had a median DB time of 485.87 ms. This is a repeatable **high-priority performance anomaly**, possibly corresponding to workflow reconciliation; cause must be verified before removing or deferring business operations.

A representative 609-query request included the following repeated query templates:

- 276 database-cache SELECT requests: 157.12 ms total
- 40 single-task lookups: 28.86 ms total
- 31 single-order lookups: 28.17 ms total
- 20 conditional task lookups: 21.07 ms total
- 20 comment lookups: 18.91 ms total
- 14 `information_schema.tables` existence checks: 10.29 ms total

There are also lazy-loading warnings for Activity `subject` relations (60 in the supplied log, seen around dashboard work) and WorkflowPhase `taskPack` (8 warnings), but they are not all necessarily attributable to Order Details. Optimize based on scoped traces rather than assuming all log warnings belong to the target page.

## Other section request timings (Laravel performance log, 16 requests per section)

| Section | Median HTTP duration (ms) | Median SQL queries |
|---|---:|---:|
| Products | 33.7 | 7 |
| Workflow (all) | 151.7 | 85 |
| Attachments | 22.9 | 12 |
| Activity | 27.9 | 15 |

## Incomplete metrics

- No structured `livewire_update` records were present in the initial JSONL. Update route detection and collect a fresh run.
- Browser navigation, FCP, cumulative layout shift, and progressive-section completion were not exported in the provided files.
- Five `supplier_pending` markers exist, but **no matching applied supplier-delivery attempt** or queue-start event was recorded in the supplied capture. End-to-end latency remains unmeasured; this alone does not prove a failed worker.
- No task-completion operation was captured with action-level timings. Do not perform destructive benchmark actions on production orders.
- User ID 58 was logged in during the measurements, whereas order-size sampling used ID 1. Re-run the sampler with ID 58 and keep all sessions aligned.

## Next controlled measurement

1. Install the small route-detection patch; do not modify workflow logic or reset task state.
2. Use `FLOWTRACK_ORDER_BASELINE_RUN_ID=before_livewire_fixed` and run `php artisan config:clear`.
3. Run `php artisan flowtrack:order-baseline-samples --user=58 --limit=500` and choose accessible orders with as much size variation as possible.
4. Repeat initial GET and all four progressive section loads at least five times per selected order; separate first-time reconciliation/cold-state runs from warmed reads.
5. Export DevTools `__flowOrderBaseline.print()` results. Check browser network resource capture and `livewire_update` entries in the JSONL.
6. Verify the supplier-critical queue worker in the relevant environment and separately measure safe task completion on disposable orders.
7. Run `python3 scripts/order-baseline-report.py --input storage/logs/order-details-baseline.jsonl --run-id before_livewire_fixed --output storage/logs/order-baseline-before-complete.md`.

Do not compare the original incomplete `before` run with optimized `after` results as if coverage were identical.
