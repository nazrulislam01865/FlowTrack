# FlowTracker Order Details - Phase 1 Baseline

Run: `AB_B1`. Samples captured: **182**. Invalid JSONL records: **0**.

## Server performance

| Action | n | Duration P50 (ms) | Duration P95 (ms) | PHP CPU P50 (ms) | Queries P50 | DB P50 (ms) | Peak memory P95 (MB) |
|---|---:|---:|---:|---:|---:|---:|---:|
| HTTP Livewire jobs.index::render / jobs.order-workflow-section::render - order 333 | 1 | 529.04 | 529.04 | 350.11 | 81.00 | 111.42 | 10.00 |
| HTTP Livewire jobs.order-activity-section::loadActivitySection - order 308 | 1 | 46.00 | 46.00 | 18.83 | 16.00 | 28.94 | 4.00 |
| HTTP Livewire jobs.order-activity-section::loadActivitySection - order 310 | 2 | 35.50 | 37.34 | 19.20 | 16.00 | 16.95 | 4.00 |
| HTTP Livewire jobs.order-activity-section::loadActivitySection - order 333 | 8 | 36.09 | 41.19 | 20.54 | 19.00 | 17.93 | 4.00 |
| HTTP Livewire jobs.order-activity-section::loadActivitySection - order 334 | 3 | 30.33 | 42.12 | 15.47 | 19.00 | 17.49 | 4.00 |
| HTTP Livewire jobs.order-attachments-section::loadAttachmentsSection - order 308 | 1 | 19.21 | 19.21 | 10.78 | 14.00 | 9.69 | 4.00 |
| HTTP Livewire jobs.order-attachments-section::loadAttachmentsSection - order 310 | 2 | 28.34 | 33.10 | 15.48 | 14.00 | 14.12 | 4.00 |
| HTTP Livewire jobs.order-attachments-section::loadAttachmentsSection - order 333 | 8 | 30.85 | 37.10 | 15.93 | 17.00 | 16.77 | 5.30 |
| HTTP Livewire jobs.order-attachments-section::loadAttachmentsSection - order 334 | 3 | 22.57 | 39.02 | 13.67 | 16.00 | 12.41 | 4.00 |
| HTTP Livewire jobs.order-products-section::loadProductsSection - order 308 | 1 | 50.45 | 50.45 | 11.51 | 7.00 | 39.88 | 4.00 |
| HTTP Livewire jobs.order-products-section::loadProductsSection - order 310 | 2 | 137.93 | 204.37 | 46.06 | 7.00 | 75.78 | 4.00 |
| HTTP Livewire jobs.order-products-section::loadProductsSection - order 333 | 9 | 29.43 | 54.59 | 16.07 | 11.00 | 10.03 | 4.00 |
| HTTP Livewire jobs.order-products-section::loadProductsSection - order 334 | 3 | 26.65 | 117.15 | 17.53 | 11.00 | 10.11 | 4.00 |
| HTTP Livewire jobs.order-workflow-section::loadWorkflowSection - order 308 | 1 | 144.36 | 144.36 | 80.13 | 70.00 | 67.64 | 6.00 |
| HTTP Livewire jobs.order-workflow-section::loadWorkflowSection - order 310 | 2 | 380.63 | 586.07 | 229.12 | 84.50 | 149.94 | 7.90 |
| HTTP Livewire jobs.order-workflow-section::loadWorkflowSection - order 333 | 8 | 173.69 | 358.30 | 95.77 | 75.00 | 85.73 | 7.30 |
| HTTP Livewire jobs.order-workflow-section::loadWorkflowSection - order 334 | 3 | 164.74 | 272.87 | 88.24 | 70.00 | 83.89 | 7.80 |
| HTTP initial GET - order 308 | 2 | 79.95 | 90.75 | 57.07 | 26.00 | 20.54 | 6.00 |
| HTTP initial GET - order 310 | 3 | 90.99 | 512.53 | 59.90 | 27.00 | 25.18 | 16.80 |
| HTTP initial GET - order 333 | 9 | 81.51 | 264.36 | 51.43 | 30.00 | 27.13 | 8.00 |
| HTTP initial GET - order 334 | 4 | 72.26 | 316.80 | 49.26 | 28.00 | 24.47 | 9.40 |
| Service artwork_repair - order 308 | 1 | 18.44 | 18.44 | 8.70 | 12.00 | 10.72 | n/a |
| Service artwork_repair - order 310 | 2 | 49.11 | 73.77 | 23.56 | 12.00 | 25.62 | n/a |
| Service artwork_repair - order 333 | 8 | 25.04 | 39.58 | 9.84 | 14.00 | 17.22 | n/a |
| Service artwork_repair - order 334 | 3 | 27.10 | 34.36 | 7.92 | 12.00 | 20.79 | n/a |
| Service remote_area_match - order 308 | 3 | 3.69 | 4.78 | 0.94 | 2.00 | 1.38 | n/a |
| Service remote_area_match - order 310 | 5 | 3.71 | 15.35 | 1.14 | 2.00 | 1.36 | n/a |
| Service remote_area_match - order 333 | 19 | 3.76 | 19.05 | 1.15 | 2.00 | 1.39 | n/a |
| Service remote_area_match - order 334 | 7 | 3.72 | 13.44 | 1.18 | 2.00 | 1.42 | n/a |
| Service workflow_auto_advance - order 308 | 1 | 12.04 | 12.04 | 5.49 | 9.00 | 7.40 | n/a |
| Service workflow_auto_advance - order 310 | 2 | 55.17 | 94.15 | 27.58 | 20.50 | 30.77 | n/a |
| Service workflow_auto_advance - order 333 | 8 | 18.58 | 71.66 | 7.45 | 12.00 | 13.44 | n/a |
| Service workflow_auto_advance - order 334 | 3 | 19.75 | 20.73 | 7.62 | 12.00 | 13.67 | n/a |
| Service workflow_detail_context - order 308 | 1 | 9.10 | 9.10 | 6.99 | 2.00 | 0.94 | n/a |
| Service workflow_detail_context - order 310 | 2 | 27.08 | 44.16 | 23.32 | 2.00 | 1.72 | n/a |
| Service workflow_detail_context - order 333 | 9 | 15.91 | 32.15 | 9.84 | 2.00 | 1.39 | n/a |
| Service workflow_detail_context - order 334 | 3 | 11.50 | 37.43 | 8.80 | 2.00 | 1.42 | n/a |
| Service workflow_load_relations - order 308 | 1 | 32.86 | 32.86 | 12.68 | 23.00 | 22.29 | n/a |
| Service workflow_load_relations - order 310 | 2 | 57.92 | 84.17 | 31.93 | 24.00 | 29.30 | n/a |
| Service workflow_load_relations - order 333 | 9 | 47.03 | 110.47 | 17.81 | 23.00 | 28.04 | n/a |
| Service workflow_load_relations - order 334 | 3 | 36.16 | 44.79 | 15.88 | 20.00 | 22.92 | n/a |
| Service workflow_sync - order 308 | 1 | 29.38 | 29.38 | 12.41 | 15.00 | 18.73 | n/a |
| Service workflow_sync - order 310 | 2 | 78.47 | 108.86 | 28.87 | 15.00 | 49.54 | n/a |
| Service workflow_sync - order 333 | 8 | 32.06 | 49.95 | 11.31 | 15.00 | 23.58 | n/a |
| Service workflow_sync - order 334 | 3 | 29.00 | 50.59 | 12.57 | 15.00 | 19.03 | n/a |

## Baseline capture coverage

Initial Order Details requests: **18**; Livewire requests: **58**; service spans: **106**.
Workflow Livewire requests with >= 500 SQL queries: **0**.

## Instrumented service spans (diagnostic only)

| Span | n | Duration P50 (ms) | Duration P95 (ms) | PHP CPU P50 (ms) | SQL P50 | DB P50 (ms) | Failed |
|---|---:|---:|---:|---:|---:|---:|---:|
| artwork_repair | 14 | 24.98 | 54.41 | 9.24 | 14.00 | 18.07 | 0 |
| remote_area_match | 34 | 3.71 | 18.19 | 1.15 | 2.00 | 1.38 | 0 |
| workflow_auto_advance | 14 | 18.58 | 98.57 | 7.48 | 12.00 | 13.22 | 0 |
| workflow_detail_context | 15 | 11.50 | 42.04 | 8.87 | 2.00 | 1.39 | 0 |
| workflow_load_relations | 15 | 43.03 | 101.19 | 17.76 | 23.00 | 24.98 | 0 |
| workflow_sync | 14 | 32.06 | 74.58 | 12.11 | 15.00 | 23.58 | 0 |

## Supplier queue and delivery

Queue delay P50/P95 (ms): **n/a / n/a**.
Observed most-recent pending mark to applied delivery P50/P95 (ms): **n/a / n/a** (matched 0 deliveries).
Note: Mark-to-delivery is a diagnostic estimate when multiple updates target one order; use event-level correlation for strict SLO evidence.

## Slowest SQL template fingerprints (no SQL text or bindings)

| Hash | Count | Total DB time (ms) |
|---|---:|---:|
| `0035bea82771517e` | 304 | 215.40 |
| `5806fa64b9db1c22` | 284 | 186.69 |
| `e71462a8311896d5` | 3 | 157.91 |
| `e12920c4d6fa9c7c` | 51 | 136.90 |
| `e8745232784c07b6` | 61 | 89.63 |
| `8f35281c0aecbe7b` | 70 | 76.16 |
| `82e63649a381d8b6` | 14 | 61.68 |
| `236e350a19445b1e` | 18 | 50.85 |
| `8a8a3de0a7cd0d3a` | 1 | 48.66 |
| `a068cb4d5bda15d9` | 43 | 45.97 |
| `abc6e517af628547` | 29 | 44.16 |
| `25e8c568b405b45d` | 13 | 40.17 |
| `2de507dddac0c5f9` | 44 | 37.73 |
| `f76327bc59ad6edf` | 8 | 37.27 |
| `dee89b265bd096ee` | 17 | 36.81 |
| `046a2d8f54846997` | 24 | 34.22 |
| `11c839dc1e99603e` | 37 | 29.94 |
| `c25b432130f3a581` | 24 | 29.49 |
| `77a678a6d0ca6fa7` | 16 | 28.55 |
| `787ee0bb13c059f4` | 25 | 28.08 |

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
