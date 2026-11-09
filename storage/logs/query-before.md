# FlowTracker Order Details - Phase 1 Baseline

Run: `QUERY_BEFORE`. Samples captured: **42**. Invalid JSONL records: **0**.

## Server performance

| Action | n | Duration P50 (ms) | Duration P95 (ms) | PHP CPU P50 (ms) | Queries P50 | DB P50 (ms) | Peak memory P95 (MB) |
|---|---:|---:|---:|---:|---:|---:|---:|
| HTTP Livewire jobs.order-activity-section::loadActivitySection - order 317 | 1 | 29.74 | 29.74 | 18.96 | 17.00 | 12.87 | 4.00 |
| HTTP Livewire jobs.order-activity-section::loadActivitySection - order 335 | 1 | 31.76 | 31.76 | 17.11 | 20.00 | 16.82 | 4.00 |
| HTTP Livewire jobs.order-activity-section::loadActivitySection - order 336 | 1 | 35.64 | 35.64 | 20.87 | 20.00 | 16.23 | 4.00 |
| HTTP Livewire jobs.order-attachments-section::loadAttachmentsSection - order 317 | 1 | 27.24 | 27.24 | 14.44 | 15.00 | 14.55 | 4.00 |
| HTTP Livewire jobs.order-attachments-section::loadAttachmentsSection - order 335 | 1 | 27.55 | 27.55 | 14.57 | 17.00 | 14.69 | 4.00 |
| HTTP Livewire jobs.order-attachments-section::loadAttachmentsSection - order 336 | 1 | 27.67 | 27.67 | 15.83 | 16.00 | 12.95 | 4.00 |
| HTTP Livewire jobs.order-products-section::loadProductsSection - order 317 | 1 | 59.98 | 59.98 | 13.11 | 7.00 | 47.71 | 4.00 |
| HTTP Livewire jobs.order-products-section::loadProductsSection - order 335 | 1 | 25.42 | 25.42 | 16.03 | 11.00 | 10.52 | 4.00 |
| HTTP Livewire jobs.order-products-section::loadProductsSection - order 336 | 1 | 67.33 | 67.33 | 53.72 | 11.00 | 11.09 | 4.00 |
| HTTP Livewire jobs.order-workflow-section::loadWorkflowSection - order 317 | 1 | 184.22 | 184.22 | 94.60 | 97.00 | 100.19 | 6.00 |
| HTTP Livewire jobs.order-workflow-section::loadWorkflowSection - order 335 | 1 | 175.77 | 175.77 | 100.34 | 91.00 | 84.91 | 6.00 |
| HTTP Livewire jobs.order-workflow-section::loadWorkflowSection - order 336 | 1 | 233.55 | 233.55 | 134.54 | 85.00 | 101.12 | 8.00 |
| HTTP initial GET - order 317 | 2 | 61.89 | 62.46 | 38.24 | 26.00 | 24.79 | 4.00 |
| HTTP initial GET - order 335 | 2 | 56.96 | 61.17 | 38.76 | 28.00 | 18.61 | 5.90 |
| HTTP initial GET - order 336 | 2 | 153.94 | 231.75 | 99.33 | 32.00 | 36.66 | 7.90 |
| Service artwork_repair - order 317 | 1 | 31.98 | 31.98 | 12.28 | 17.00 | 21.98 | n/a |
| Service artwork_repair - order 335 | 1 | 18.59 | 18.59 | 7.15 | 12.00 | 12.98 | n/a |
| Service artwork_repair - order 336 | 1 | 11.70 | 11.70 | 2.47 | 3.00 | 9.44 | n/a |
| Service remote_area_match - order 317 | 3 | 3.23 | 3.80 | 0.88 | 2.00 | 1.42 | n/a |
| Service remote_area_match - order 335 | 3 | 3.56 | 3.96 | 0.90 | 2.00 | 1.29 | n/a |
| Service remote_area_match - order 336 | 3 | 3.50 | 33.51 | 0.91 | 2.00 | 1.36 | n/a |
| Service workflow_auto_advance - order 317 | 1 | 42.07 | 42.07 | 17.89 | 30.00 | 27.66 | n/a |
| Service workflow_auto_advance - order 335 | 1 | 40.24 | 40.24 | 17.12 | 32.00 | 26.71 | n/a |
| Service workflow_auto_advance - order 336 | 1 | 46.39 | 46.39 | 19.08 | 34.00 | 29.42 | n/a |
| Service workflow_detail_context - order 317 | 1 | 9.66 | 9.66 | 7.80 | 2.00 | 0.84 | n/a |
| Service workflow_detail_context - order 335 | 1 | 12.02 | 12.02 | 9.66 | 2.00 | 1.27 | n/a |
| Service workflow_detail_context - order 336 | 1 | 25.13 | 25.13 | 21.18 | 2.00 | 1.36 | n/a |
| Service workflow_load_relations - order 317 | 1 | 34.62 | 34.62 | 15.06 | 23.00 | 22.21 | n/a |
| Service workflow_load_relations - order 335 | 1 | 33.03 | 33.03 | 15.45 | 20.00 | 20.17 | n/a |
| Service workflow_load_relations - order 336 | 1 | 34.49 | 34.49 | 14.75 | 19.00 | 20.89 | n/a |
| Service workflow_sync - order 317 | 1 | 26.72 | 26.72 | 10.65 | 15.00 | 17.93 | n/a |
| Service workflow_sync - order 335 | 1 | 24.35 | 24.35 | 11.06 | 15.00 | 15.06 | n/a |
| Service workflow_sync - order 336 | 1 | 37.43 | 37.43 | 11.11 | 15.00 | 27.65 | n/a |

## Baseline capture coverage

Initial Order Details requests: **6**; Livewire requests: **12**; service spans: **24**.
Workflow Livewire requests with >= 500 SQL queries: **0**.

## Instrumented service spans (diagnostic only)

| Span | n | Duration P50 (ms) | Duration P95 (ms) | PHP CPU P50 (ms) | SQL P50 | DB P50 (ms) | Failed |
|---|---:|---:|---:|---:|---:|---:|---:|
| artwork_repair | 3 | 18.59 | 30.64 | 7.15 | 12.00 | 12.98 | 0 |
| remote_area_match | 9 | 3.50 | 23.70 | 0.90 | 2.00 | 1.36 | 0 |
| workflow_auto_advance | 3 | 42.07 | 45.96 | 17.89 | 32.00 | 27.66 | 0 |
| workflow_detail_context | 3 | 12.02 | 23.82 | 9.66 | 2.00 | 1.27 | 0 |
| workflow_load_relations | 3 | 34.49 | 34.61 | 15.06 | 20.00 | 20.89 | 0 |
| workflow_sync | 3 | 26.72 | 36.36 | 11.06 | 15.00 | 17.93 | 0 |

## Supplier queue and delivery

Queue delay P50/P95 (ms): **n/a / n/a**.
Observed most-recent pending mark to applied delivery P50/P95 (ms): **n/a / n/a** (matched 0 deliveries).
Note: Mark-to-delivery is a diagnostic estimate when multiple updates target one order; use event-level correlation for strict SLO evidence.

## Slowest SQL template fingerprints (no SQL text or bindings)

| Hash | Count | Total DB time (ms) |
|---|---:|---:|
| `0035bea82771517e` | 78 | 61.47 |
| `e71462a8311896d5` | 1 | 41.84 |
| `e12920c4d6fa9c7c` | 13 | 26.15 |
| `5806fa64b9db1c22` | 31 | 19.07 |
| `e8745232784c07b6` | 15 | 16.76 |
| `08d22092ef689f16` | 25 | 13.41 |
| `2de507dddac0c5f9` | 12 | 12.32 |
| `8f35281c0aecbe7b` | 13 | 12.18 |
| `a068cb4d5bda15d9` | 9 | 8.50 |
| `77a678a6d0ca6fa7` | 6 | 8.34 |
| `82e63649a381d8b6` | 3 | 7.91 |
| `2745b8c8ab13f133` | 5 | 7.75 |
| `f72add47a1df209f` | 10 | 7.46 |
| `dee89b265bd096ee` | 5 | 7.44 |
| `046a2d8f54846997` | 6 | 6.55 |
| `52ccb20fb21947d9` | 6 | 6.03 |
| `787ee0bb13c059f4` | 7 | 5.81 |
| `b955d379ea85b23c` | 3 | 5.60 |
| `f76327bc59ad6edf` | 1 | 5.51 |
| `25e8c568b405b45d` | 2 | 5.46 |

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
