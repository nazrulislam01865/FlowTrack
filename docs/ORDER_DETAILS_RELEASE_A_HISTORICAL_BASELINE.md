# FlowTracker Order Details - Phase 1 Baseline

Run: `phase5_summary_on`. Samples captured: **74**. Invalid JSONL records: **0**.

## Server performance

| Action | n | Duration P50 (ms) | Duration P95 (ms) | PHP CPU P50 (ms) | Queries P50 | DB P50 (ms) | Peak memory P95 (MB) |
|---|---:|---:|---:|---:|---:|---:|---:|
| HTTP Livewire jobs.order-activity-section::loadActivitySection - order 333 | 2 | 33.92 | 37.93 | 20.32 | 19.00 | 15.55 | 4.00 |
| HTTP Livewire jobs.order-activity-section::loadActivitySection - order 334 | 1 | 30.95 | 30.95 | 13.56 | 19.00 | 18.31 | 4.00 |
| HTTP Livewire jobs.order-attachments-section::loadAttachmentsSection - order 333 | 3 | 33.69 | 34.21 | 18.64 | 17.00 | 15.81 | 4.00 |
| HTTP Livewire jobs.order-attachments-section::loadAttachmentsSection - order 334 | 1 | 26.40 | 26.40 | 14.48 | 15.00 | 13.22 | 4.00 |
| HTTP Livewire jobs.order-products-section::loadProductsSection - order 333 | 3 | 45.84 | 54.17 | 34.05 | 11.00 | 11.02 | 4.00 |
| HTTP Livewire jobs.order-products-section::loadProductsSection - order 334 | 1 | 33.79 | 33.79 | 19.43 | 11.00 | 9.80 | 6.00 |
| HTTP Livewire jobs.order-workflow-section::loadWorkflowSection - order 333 | 3 | 244.15 | 330.45 | 138.61 | 90.00 | 110.68 | 8.00 |
| HTTP Livewire jobs.order-workflow-section::loadWorkflowSection - order 334 | 1 | 160.37 | 160.37 | 95.84 | 81.00 | 68.10 | 6.00 |
| HTTP initial GET - order 333 | 5 | 88.15 | 333.39 | 52.63 | 30.00 | 29.94 | 9.20 |
| Service artwork_repair - order 333 | 3 | 12.23 | 22.20 | 3.07 | 3.00 | 9.47 | n/a |
| Service artwork_repair - order 334 | 1 | 7.47 | 7.47 | 2.05 | 3.00 | 5.54 | n/a |
| Service remote_area_match - order 333 | 8 | 3.24 | 31.70 | 1.00 | 2.00 | 1.50 | n/a |
| Service remote_area_match - order 334 | 1 | 2.29 | 2.29 | 0.82 | 2.00 | 1.02 | n/a |
| Service workflow_auto_advance - order 333 | 3 | 35.46 | 73.67 | 13.80 | 30.00 | 23.73 | n/a |
| Service workflow_auto_advance - order 334 | 1 | 30.73 | 30.73 | 14.31 | 29.00 | 18.45 | n/a |
| Service workflow_detail_context - order 333 | 3 | 22.48 | 23.97 | 19.91 | 2.00 | 0.85 | n/a |
| Service workflow_detail_context - order 334 | 1 | 27.24 | 27.24 | 23.81 | 2.00 | 1.02 | n/a |
| Service workflow_load_relations - order 333 | 3 | 40.70 | 47.68 | 17.53 | 23.00 | 24.85 | n/a |
| Service workflow_load_relations - order 334 | 1 | 29.01 | 29.01 | 11.08 | 19.00 | 19.79 | n/a |
| Service workflow_sync - order 333 | 3 | 47.16 | 67.79 | 14.44 | 16.00 | 34.13 | n/a |
| Service workflow_sync - order 334 | 1 | 24.31 | 24.31 | 11.43 | 16.00 | 14.49 | n/a |
| supplier_pending - order 332 | 1 | n/a | n/a | n/a | n/a | n/a | n/a |
| supplier_pending - order 334 | 24 | n/a | n/a | n/a | n/a | n/a | n/a |

## Baseline capture coverage

Initial Order Details requests: **5**; Livewire requests: **15**; service spans: **29**.
Workflow Livewire requests with >= 500 SQL queries: **0**.

## Instrumented service spans (diagnostic only)

| Span | n | Duration P50 (ms) | Duration P95 (ms) | PHP CPU P50 (ms) | SQL P50 | DB P50 (ms) | Failed |
|---|---:|---:|---:|---:|---:|---:|---:|
| artwork_repair | 4 | 9.85 | 21.65 | 2.56 | 3.00 | 7.51 | 0 |
| remote_area_match | 9 | 2.95 | 30.61 | 0.90 | 2.00 | 1.36 | 0 |
| workflow_auto_advance | 4 | 33.09 | 71.55 | 14.05 | 30.00 | 21.09 | 0 |
| workflow_detail_context | 4 | 23.31 | 26.77 | 20.44 | 2.00 | 0.94 | 0 |
| workflow_load_relations | 4 | 34.86 | 47.30 | 14.96 | 23.00 | 22.32 | 0 |
| workflow_sync | 4 | 35.73 | 66.64 | 12.93 | 16.00 | 24.31 | 0 |

## Supplier queue and delivery

Queue delay P50/P95 (ms): **n/a / n/a**.
Observed most-recent pending mark to applied delivery P50/P95 (ms): **n/a / n/a** (matched 0 deliveries).
Note: Mark-to-delivery is a diagnostic estimate when multiple updates target one order; use event-level correlation for strict SLO evidence.

## Slowest SQL template fingerprints (no SQL text or bindings)

| Hash | Count | Total DB time (ms) |
|---|---:|---:|
| `0035bea82771517e` | 81 | 56.96 |
| `5806fa64b9db1c22` | 90 | 46.24 |
| `e12920c4d6fa9c7c` | 14 | 31.66 |
| `abc6e517af628547` | 10 | 20.19 |
| `e8745232784c07b6` | 14 | 18.59 |
| `236e350a19445b1e` | 6 | 17.91 |
| `dee89b265bd096ee` | 5 | 17.50 |
| `82e63649a381d8b6` | 4 | 15.26 |
| `2de507dddac0c5f9` | 10 | 13.56 |
| `7d5d2af8b8654b21` | 15 | 12.29 |
| `f76327bc59ad6edf` | 3 | 11.19 |
| `a068cb4d5bda15d9` | 11 | 10.77 |
| `046a2d8f54846997` | 8 | 10.18 |
| `5e2bcbc4d615b4c9` | 9 | 10.10 |
| `11c839dc1e99603e` | 11 | 9.70 |
| `77a678a6d0ca6fa7` | 5 | 9.53 |
| `b955d379ea85b23c` | 4 | 9.00 |
| `d73b0ed9b2c21313` | 3 | 8.94 |
| `5715b837a01014c3` | 2 | 8.92 |
| `8f35281c0aecbe7b` | 11 | 8.76 |

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
