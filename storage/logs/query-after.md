# FlowTracker Order Details - Phase 1 Baseline

Run: `QUERY_AFTER`. Samples captured: **48**. Invalid JSONL records: **0**.

## Server performance

| Action | n | Duration P50 (ms) | Duration P95 (ms) | PHP CPU P50 (ms) | Queries P50 | DB P50 (ms) | Peak memory P95 (MB) |
|---|---:|---:|---:|---:|---:|---:|---:|
| HTTP Livewire jobs.order-activity-section::loadActivitySection - order 317 | 1 | 32.00 | 32.00 | 17.57 | 17.00 | 15.54 | 4.00 |
| HTTP Livewire jobs.order-activity-section::loadActivitySection - order 336 | 1 | 28.84 | 28.84 | 15.07 | 20.00 | 15.97 | 4.00 |
| HTTP Livewire jobs.order-attachments-section::loadAttachmentsSection - order 317 | 3 | 22.26 | 25.01 | 12.82 | 15.00 | 11.97 | 4.00 |
| HTTP Livewire jobs.order-attachments-section::loadAttachmentsSection - order 336 | 1 | 24.74 | 24.74 | 13.11 | 16.00 | 13.52 | 4.00 |
| HTTP Livewire jobs.order-products-section::loadProductsSection - order 317 | 3 | 38.52 | 42.68 | 8.97 | 7.00 | 20.63 | 4.00 |
| HTTP Livewire jobs.order-products-section::loadProductsSection - order 336 | 1 | 17.01 | 17.01 | 10.48 | 11.00 | 7.58 | 4.00 |
| HTTP Livewire jobs.order-workflow-section::loadWorkflowSection - order 317 | 3 | 157.09 | 212.76 | 72.85 | 97.00 | 92.28 | 6.00 |
| HTTP Livewire jobs.order-workflow-section::loadWorkflowSection - order 336 | 1 | 171.37 | 171.37 | 86.27 | 83.00 | 93.45 | 6.00 |
| HTTP initial GET - order 317 | 4 | 106.75 | 202.37 | 52.05 | 26.00 | 26.15 | 7.70 |
| HTTP initial GET - order 336 | 1 | 71.22 | 71.22 | 44.98 | 30.00 | 27.47 | 6.00 |
| Service artwork_repair - order 317 | 3 | 27.27 | 36.22 | 8.92 | 17.00 | 20.22 | n/a |
| Service artwork_repair - order 336 | 1 | 5.12 | 5.12 | 1.37 | 3.00 | 4.11 | n/a |
| Service remote_area_match - order 317 | 7 | 3.99 | 9.90 | 1.11 | 2.00 | 1.45 | n/a |
| Service remote_area_match - order 336 | 2 | 3.94 | 4.44 | 1.22 | 2.00 | 1.23 | n/a |
| Service workflow_auto_advance - order 317 | 3 | 33.39 | 47.45 | 12.81 | 30.00 | 23.07 | n/a |
| Service workflow_auto_advance - order 336 | 1 | 42.55 | 42.55 | 20.34 | 34.00 | 26.18 | n/a |
| Service workflow_detail_context - order 317 | 3 | 8.77 | 19.33 | 6.12 | 2.00 | 1.34 | n/a |
| Service workflow_detail_context - order 336 | 1 | 10.33 | 10.33 | 8.01 | 2.00 | 1.13 | n/a |
| Service workflow_load_relations - order 317 | 3 | 37.74 | 37.80 | 14.52 | 24.00 | 20.48 | n/a |
| Service workflow_load_relations - order 336 | 1 | 53.74 | 53.74 | 17.72 | 20.00 | 38.31 | n/a |
| Service workflow_sync - order 317 | 3 | 20.90 | 32.48 | 10.15 | 15.00 | 14.25 | n/a |
| Service workflow_sync - order 336 | 1 | 24.81 | 24.81 | 10.12 | 15.00 | 16.37 | n/a |

## Baseline capture coverage

Initial Order Details requests: **5**; Livewire requests: **14**; service spans: **29**.
Workflow Livewire requests with >= 500 SQL queries: **0**.

## Instrumented service spans (diagnostic only)

| Span | n | Duration P50 (ms) | Duration P95 (ms) | PHP CPU P50 (ms) | SQL P50 | DB P50 (ms) | Failed |
|---|---:|---:|---:|---:|---:|---:|---:|
| artwork_repair | 4 | 21.45 | 35.73 | 8.77 | 17.00 | 14.49 | 0 |
| remote_area_match | 9 | 3.99 | 9.28 | 1.16 | 2.00 | 1.34 | 0 |
| workflow_auto_advance | 4 | 37.97 | 48.04 | 15.73 | 30.00 | 24.62 | 0 |
| workflow_detail_context | 4 | 9.55 | 18.97 | 7.06 | 2.00 | 1.23 | 0 |
| workflow_load_relations | 4 | 37.78 | 51.35 | 16.12 | 24.00 | 23.13 | 0 |
| workflow_sync | 4 | 22.85 | 32.43 | 10.13 | 15.00 | 15.31 | 0 |

## Supplier queue and delivery

Queue delay P50/P95 (ms): **n/a / n/a**.
Observed most-recent pending mark to applied delivery P50/P95 (ms): **n/a / n/a** (matched 0 deliveries).
Note: Mark-to-delivery is a diagnostic estimate when multiple updates target one order; use event-level correlation for strict SLO evidence.

## Slowest SQL template fingerprints (no SQL text or bindings)

| Hash | Count | Total DB time (ms) |
|---|---:|---:|
| `e71462a8311896d5` | 3 | 54.05 |
| `0035bea82771517e` | 79 | 53.01 |
| `8a8a3de0a7cd0d3a` | 1 | 37.65 |
| `e12920c4d6fa9c7c` | 12 | 23.70 |
| `5cab8d3b5e1df7c9` | 1 | 21.06 |
| `e8745232784c07b6` | 17 | 19.52 |
| `8f35281c0aecbe7b` | 18 | 15.48 |
| `5806fa64b9db1c22` | 27 | 14.28 |
| `6f0a705d3084b3f9` | 15 | 10.56 |
| `2745b8c8ab13f133` | 8 | 10.13 |
| `f72add47a1df209f` | 10 | 9.61 |
| `2de507dddac0c5f9` | 10 | 9.20 |
| `a068cb4d5bda15d9` | 10 | 8.82 |
| `046a2d8f54846997` | 8 | 8.13 |
| `37ef129d412cde86` | 6 | 7.34 |
| `82e63649a381d8b6` | 3 | 6.91 |
| `c25b432130f3a581` | 6 | 6.54 |
| `25e8c568b405b45d` | 2 | 6.41 |
| `8c085e865b0eb5ef` | 2 | 6.33 |
| `52ccb20fb21947d9` | 5 | 6.14 |

**Failed actions/requests recorded:** 0.

## Interpretation notes

- Request timings begin in the web performance middleware; they exclude some PHP bootstrap work. Browser navigation entries cover end-to-end GET.
- Supplier POST handling is timed independently of the web middleware; no request payloads or authentication secrets are recorded.
- `task_status_mutation` can be nested inside `task_workflow_action`; do not sum these durations.
- Peak PHP memory is a process/request memory high-water mark (not precise per-component allocation).
- PHP CPU time uses process-local getrusage() and may be unavailable; it excludes SQL server, queue workers and browser CPU.
- Service spans can overlap/nest within HTTP requests; never add their totals to request duration or request CPU.
- Old JSONL request kinds initial_get/livewire_update are supported; new schema uses kind=http_request and request_kind.
- Browser visual readiness/FCP/layout shift measurements are exported separately with `__flowOrderBaseline.print()`; they are not inferred from server logs.
- A server with no configured database, queue worker, signed supplier portal or authenticated browser cannot supply live measurements.
- Repeat all scenarios in the same environment, logged-in user, browser, and data set. Record at least five repeats per order size, warm and cold separately.
