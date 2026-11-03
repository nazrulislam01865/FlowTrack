# FlowTracker Order Details - Phase 1 Baseline

Run: `phase5_summary_on`. Samples captured: **24**. Invalid JSONL records: **0**.

## Server performance

| Action | n | Duration P50 (ms) | Duration P95 (ms) | PHP CPU P50 (ms) | Queries P50 | DB P50 (ms) | Peak memory P95 (MB) |
|---|---:|---:|---:|---:|---:|---:|---:|
| HTTP Livewire jobs.order-activity-section::loadActivitySection - order 333 | 2 | 33.92 | 37.93 | 20.32 | 19.00 | 15.55 | 4.00 |
| HTTP Livewire jobs.order-attachments-section::loadAttachmentsSection - order 333 | 2 | 31.12 | 33.96 | 16.34 | 17.00 | 16.52 | 4.00 |
| HTTP Livewire jobs.order-products-section::loadProductsSection - order 333 | 2 | 40.03 | 53.59 | 25.65 | 11.00 | 13.36 | 4.00 |
| HTTP Livewire jobs.order-workflow-section::loadWorkflowSection - order 333 | 2 | 226.06 | 328.64 | 130.04 | 87.00 | 100.05 | 7.90 |
| HTTP initial GET - order 333 | 2 | 217.13 | 340.89 | 155.52 | 33.00 | 43.84 | 9.80 |
| Service artwork_repair - order 333 | 2 | 7.51 | 11.76 | 2.09 | 3.00 | 5.70 | n/a |
| Service remote_area_match - order 333 | 4 | 3.09 | 15.42 | 1.00 | 2.00 | 1.30 | n/a |
| Service workflow_auto_advance - order 333 | 2 | 51.09 | 75.24 | 23.50 | 30.00 | 29.95 | n/a |
| Service workflow_detail_context - order 333 | 2 | 15.92 | 23.32 | 13.80 | 2.00 | 0.90 | n/a |
| Service workflow_load_relations - order 333 | 2 | 34.86 | 47.10 | 18.20 | 23.00 | 18.57 | n/a |
| Service workflow_sync - order 333 | 2 | 43.38 | 67.41 | 13.94 | 16.00 | 30.87 | n/a |

## Baseline capture coverage

Initial Order Details requests: **2**; Livewire requests: **8**; service spans: **14**.
Workflow Livewire requests with >= 500 SQL queries: **0**.

## Instrumented service spans (diagnostic only)

| Span | n | Duration P50 (ms) | Duration P95 (ms) | PHP CPU P50 (ms) | SQL P50 | DB P50 (ms) | Failed |
|---|---:|---:|---:|---:|---:|---:|---:|
| artwork_repair | 2 | 7.51 | 11.76 | 2.09 | 3.00 | 5.70 | 0 |
| remote_area_match | 4 | 3.09 | 15.42 | 1.00 | 2.00 | 1.30 | 0 |
| workflow_auto_advance | 2 | 51.09 | 75.24 | 23.50 | 30.00 | 29.95 | 0 |
| workflow_detail_context | 2 | 15.92 | 23.32 | 13.80 | 2.00 | 0.90 | 0 |
| workflow_load_relations | 2 | 34.86 | 47.10 | 18.20 | 23.00 | 18.57 | 0 |
| workflow_sync | 2 | 43.38 | 67.41 | 13.94 | 16.00 | 30.87 | 0 |

## Supplier queue and delivery

Queue delay P50/P95 (ms): **n/a / n/a**.
Observed most-recent pending mark to applied delivery P50/P95 (ms): **n/a / n/a** (matched 0 deliveries).
Note: Mark-to-delivery is a diagnostic estimate when multiple updates target one order; use event-level correlation for strict SLO evidence.

## Slowest SQL template fingerprints (no SQL text or bindings)

| Hash | Count | Total DB time (ms) |
|---|---:|---:|
| `0035bea82771517e` | 38 | 29.16 |
| `5806fa64b9db1c22` | 48 | 26.21 |
| `e12920c4d6fa9c7c` | 7 | 14.80 |
| `236e350a19445b1e` | 4 | 11.45 |
| `abc6e517af628547` | 6 | 9.28 |
| `82e63649a381d8b6` | 2 | 9.11 |
| `e8745232784c07b6` | 7 | 9.00 |
| `dee89b265bd096ee` | 2 | 8.41 |
| `7d5d2af8b8654b21` | 10 | 8.19 |
| `d73b0ed9b2c21313` | 2 | 7.66 |
| `9e76e74c674766e2` | 2 | 6.47 |
| `11c839dc1e99603e` | 6 | 6.42 |
| `0f32a771b17cdf4d` | 2 | 5.95 |
| `a068cb4d5bda15d9` | 6 | 5.93 |
| `5e2bcbc4d615b4c9` | 6 | 5.79 |
| `84adcdc954c74485` | 4 | 5.69 |
| `2de507dddac0c5f9` | 4 | 5.49 |
| `5715b837a01014c3` | 1 | 5.35 |
| `046a2d8f54846997` | 4 | 5.18 |
| `f76327bc59ad6edf` | 1 | 4.90 |

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
