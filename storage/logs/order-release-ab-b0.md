# FlowTracker Order Details - Phase 1 Baseline

Run: `AB_B0`. Samples captured: **139**. Invalid JSONL records: **0**.

## Server performance

| Action | n | Duration P50 (ms) | Duration P95 (ms) | PHP CPU P50 (ms) | Queries P50 | DB P50 (ms) | Peak memory P95 (MB) |
|---|---:|---:|---:|---:|---:|---:|---:|
| HTTP Livewire jobs.order-activity-section::loadActivitySection - order 333 | 4 | 40.54 | 46.90 | 22.09 | 19.00 | 20.46 | 4.00 |
| HTTP Livewire jobs.order-attachments-section::loadAttachmentsSection - order 333 | 5 | 32.61 | 38.36 | 16.28 | 17.00 | 15.32 | 4.00 |
| HTTP Livewire jobs.order-products-section::loadProductsSection - order 333 | 13 | 19.96 | 42.25 | 11.84 | 11.00 | 8.47 | 4.00 |
| HTTP Livewire jobs.order-workflow-section::loadWorkflowSection - order 333 | 13 | 194.16 | 279.44 | 103.73 | 98.00 | 97.39 | 6.80 |
| HTTP initial GET - order 333 | 13 | 70.07 | 155.09 | 45.08 | 30.00 | 24.21 | 6.80 |
| Service artwork_repair - order 333 | 13 | 21.55 | 30.73 | 8.20 | 14.00 | 15.17 | n/a |
| Service remote_area_match - order 333 | 26 | 3.56 | 5.78 | 1.01 | 2.00 | 1.40 | n/a |
| Service workflow_auto_advance - order 333 | 13 | 48.01 | 58.83 | 20.35 | 35.00 | 31.71 | n/a |
| Service workflow_detail_context - order 333 | 13 | 10.79 | 20.25 | 8.03 | 2.00 | 1.10 | n/a |
| Service workflow_load_relations - order 333 | 13 | 36.33 | 72.85 | 16.67 | 23.00 | 23.10 | n/a |
| Service workflow_sync - order 333 | 13 | 24.66 | 39.43 | 10.55 | 15.00 | 15.60 | n/a |

## Baseline capture coverage

Initial Order Details requests: **13**; Livewire requests: **35**; service spans: **91**.
Workflow Livewire requests with >= 500 SQL queries: **0**.

## Instrumented service spans (diagnostic only)

| Span | n | Duration P50 (ms) | Duration P95 (ms) | PHP CPU P50 (ms) | SQL P50 | DB P50 (ms) | Failed |
|---|---:|---:|---:|---:|---:|---:|---:|
| artwork_repair | 13 | 21.55 | 30.73 | 8.20 | 14.00 | 15.17 | 0 |
| remote_area_match | 26 | 3.56 | 5.78 | 1.01 | 2.00 | 1.40 | 0 |
| workflow_auto_advance | 13 | 48.01 | 58.83 | 20.35 | 35.00 | 31.71 | 0 |
| workflow_detail_context | 13 | 10.79 | 20.25 | 8.03 | 2.00 | 1.10 | 0 |
| workflow_load_relations | 13 | 36.33 | 72.85 | 16.67 | 23.00 | 23.10 | 0 |
| workflow_sync | 13 | 24.66 | 39.43 | 10.55 | 15.00 | 15.60 | 0 |

## Supplier queue and delivery

Queue delay P50/P95 (ms): **n/a / n/a**.
Observed most-recent pending mark to applied delivery P50/P95 (ms): **n/a / n/a** (matched 0 deliveries).
Note: Mark-to-delivery is a diagnostic estimate when multiple updates target one order; use event-level correlation for strict SLO evidence.

## Slowest SQL template fingerprints (no SQL text or bindings)

| Hash | Count | Total DB time (ms) |
|---|---:|---:|
| `5806fa64b9db1c22` | 227 | 153.61 |
| `0035bea82771517e` | 213 | 141.54 |
| `8f35281c0aecbe7b` | 65 | 56.06 |
| `e12920c4d6fa9c7c` | 26 | 50.78 |
| `7d5d2af8b8654b21` | 65 | 50.57 |
| `046a2d8f54846997` | 26 | 42.58 |
| `abc6e517af628547` | 26 | 38.33 |
| `e8745232784c07b6` | 33 | 36.34 |
| `9e76e74c674766e2` | 4 | 32.72 |
| `41687e6a340e1e01` | 36 | 32.42 |
| `084a4329497c6cef` | 22 | 31.80 |
| `2de507dddac0c5f9` | 30 | 25.77 |
| `a068cb4d5bda15d9` | 22 | 23.38 |
| `7e942b197987fda6` | 13 | 23.10 |
| `11c839dc1e99603e` | 17 | 22.32 |
| `87eedd130dc3a81d` | 19 | 20.90 |
| `25e8c568b405b45d` | 9 | 19.24 |
| `d73b0ed9b2c21313` | 13 | 17.56 |
| `dee89b265bd096ee` | 12 | 14.47 |
| `3b7f4574431205a2` | 2 | 14.11 |

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
