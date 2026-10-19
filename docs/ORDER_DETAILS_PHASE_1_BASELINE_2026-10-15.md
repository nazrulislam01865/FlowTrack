# FlowTracker Order Details - Phase 1 Baseline (15 October 2026)

## Scope and outcome

This release provides **opt-in baseline instrumentation** for FlowTracker Order Details, rather than optimization or business-rule changes. It does not include production measurements because no configured application/database, authenticated browser, worker or supplier endpoint is available in this review environment. No speed improvements are claimed.

Existing `RequestPerformanceMonitor` is reused for middleware request durations, count and cumulative time of SQL queries, and PHP high-water memory. No second global `DB::listen` was added. The new recorder stores one structured JSON line per event in `storage/logs/order-details-baseline.jsonl` with no SQL text, SQL bindings, user input, filenames, uploads, tokens, remote URLs, passwords or authentication headers. Database SQL statement shapes are logged only as short SHA-256 fingerprints. The collector silently fails if the log path cannot be written: telemetry cannot block an order operation.

## Modified instrumentation boundaries

| Measurement | Location | Event | Definition |
|---|---|---|---|
| Initial Order Details server GET | `MonitorPerformance` + `RequestPerformanceMonitor` | `http_request`, `initial_get` | Within web middleware; excludes some pre-middleware startup |
| Isolated Livewire sections and common interactions | same monitor | `http_request`, `livewire_update` | HTTP request time, SQL count/time, PHP memory, component/method names (no parameters), payload headers if set |
| Task status mutation | `TaskService::moveStatus` | `task_status_mutation` | Transaction and status update, including nested calls |
| Canonical workflow task action | `OrderWorkflowActionService::perform` | `task_workflow_action` | End-to-end service action including DB work |
| Supplier task action on FlowTracker | `ReceiveSupplierTaskAction` | `supplier_task_action_http` | Signed API handler execution (valid or rejected), no payload |
| Changed order marked for delivery | `QueueChangedOrder::mark` | `supplier_pending` | After database commit where applicable |
| Critical queue lag | `DeliverChangedSupplierOrder` | `supplier_queue_started` | Dispatch timestamp to worker start, when delivered by this queue |
| Supplier snapshot construction + remote delivery | `DeliverOrder::send` | `supplier_delivery_attempt` | Delivery attempt including projection, HTTP, acknowledgement and notification |
| Browser/navigation/Livewire network | `public/js/order-baseline.js` | Browser-only export | Navigation TTFB, DOMContentLoaded, load, FCP, layout shift, long tasks, Livewire fetches, progressively loaded placeholders |

## 1. Enable locally or on a **controlled staging instance**

Add to your non-committed `.env`:

```dotenv
FLOWTRACK_ORDER_BASELINE_ENABLED=true
FLOWTRACK_ORDER_BASELINE_RUN_ID=before
```

If configuration is cached, run `php artisan config:cache` to apply the new values. No migrations, new tables, packages, frontend builds, or queue configuration changes are required for this instrument-only release. Restart an existing long-lived queue worker **after** deployment so it loads the new diagnostic code (`php artisan queue:restart` only if queue workers are already correctly configured). Disable the flag and refresh config after measurement. Do **not** enable high-volume global `PERFORMANCE_LOG_ALL_REQUESTS` or sensitive `PERFORMANCE_INCLUDE_QUERY_SQL` for this experiment.

The collector writes `storage/logs/order-details-baseline.jsonl`. Protect this file with ordinary log access permissions and rotation policies; do not publish or commit the log. It is not served from `/public`.

## 2. Select comparable small/medium/large orders

With a connected **staging database** and a user already permitted to see the selected orders:

```bash
php artisan flowtrack:order-baseline-samples --user=YOUR_TEST_USER_ID --limit=500
```

The read-only selector ranks up to 500 *visible* orders by `2 x tasks + documents + products` and chooses approximate 10th, 50th and 90th percentiles. It does not read other users' private order contents or change data. Use exactly the same **logged-in user** (identical role, workspace and session) for all browser tests. Confirm that returned IDs have different sizes; if not, increase `--limit` (up to 2000) or deliberately select three visible orders with different task/document complexity. Freeze sample data for the duration of comparative testing.

## 3. Reproduce each scenario

For EACH order ID and at least five repeats (warm and cold runs recorded separately):

1. Open `https://YOUR_STAGING_HOST/orders?open=ORDER_ID` with the selected test account. For cold runs use a fresh browser context or disabled browser cache; do not clear shared production caches.
2. In browser developer tools, use Network with **Preserve log** and check document/Livewire request waterfall; note whether navigation was full GET or SPA navigation. Hard-reload for comparable initial GET measurements.
3. Scroll to Products, Workflow, Attachments and Activity to trigger progressive loading, then run `__flowOrderBaseline.print()` in DevTools Console. Save the returned JSON into a named local browser-baseline file for that order/repeat (no automatic network upload). Each isolated `load*Section` call is also measured server-side.
4. Open/close relevant modal, switch each tab and perform read-only UI interactions; record client and server timings. Do not complete real production tasks as a benchmark.
5. On disposable test orders only, complete task A from FlowTracker and a separate task from Supplier Portal. Capture task action + queue delays, observe whether next task becomes active **without opening Order Details**. Record retries, failed requests and the supplier update in the test environment.
6. Check the `supplier-critical` worker is actually running, and log queue driver/backlog and deployment node to interpret delays. Do not run duplicate long-lived workers on a production system to manufacture faster results.

Review separate `http_request` entries for `initial_get` and `livewire_update`; use `kind` + `components[].methods` to isolate specific section loads. A request containing multiple Livewire components cannot be precisely split without component-level instrumentation, so classify it as a combined request rather than attributing the entire duration to each child.

**Queue limitation:** A delivery from the periodic scheduler lacks `supplier_queue_started`, but still produces `supplier_delivery_attempt`. Mark-to-applied-delivery latency is estimated by the report from the most recent order-level pending mark; repeated marks may overlap and are not a strict unique-event SLO.

## 4. Generate results

From the project root after running the scenarios:

```bash
python3 scripts/order-baseline-report.py \
  --input storage/logs/order-details-baseline.jsonl \
  --run-id before \
  --output storage/logs/order-baseline-before.md
```

Use a new `FLOWTRACK_ORDER_BASELINE_RUN_ID=after` **only after** a future optimization phase and rerun identical scenarios. You can then generate separate before/after reports. No report line is filled with invented timings when no data exists.

## 5. Interpretation and acceptance criteria

| Measurement | Target for later optimization | Phase 1 evidence |
|---|---|---|
| Initial GET and usable content | 1-2 seconds under defined conditions | Server request record + browser FCP/DOMContentLoaded/Load |
| Isolated section loads | Avoid unnecessary queries and oversized payloads | Per-Livewire request count/time, SQL fingerprints, memory |
| Common UI actions | 300-500 ms where feasible | Livewire call duration and browser fetch durations |
| Task progression | Automatic completion-to-next-task availability | Task workflow/status events and independent staging verification |
| Critical supplier updates | 2-5 seconds where feasible | Pending mark, queue delay, delivery attempt, browser observation |
| SQL and memory | Fewer redundant queries, lower overhead | Query count/time and peak memory MB |
| UI stability | No flash or unintended shift | Browser cumulative layout shift and visual inspection |

**Important:** The initial GET and Livewire update measurements are taken by existing web middleware. Pre-middleware process boot, actual user-perceived paint and individual network round trips cannot be inferred exclusively from this server log. Browser data is local-only and must be recorded independently. Query fingerprints are identifiers, not actionable `EXPLAIN` output; link slow shapes to staging query logs manually before changing indexes.

## 6. Reversal

Disable `FLOWTRACK_ORDER_BASELINE_ENABLED`; refresh Laravel's cached configuration if in use. Diagnostic code paths then become inactive. The `order-details-baseline.jsonl` log can be archived or removed per normal data retention rules. The source code is otherwise fully compatible with existing application behavior and does not add a database migration.

## Livewire baseline route compatibility (October 15 correction)

Livewire can register its update endpoint under a hashed URI rather than the literal
`/livewire/update`. The baseline recorder now identifies `default-livewire.update`
by its Laravel route name, with the conventional path retained as a fallback.
The browser performance observer also recognizes both URI forms. This corrects
**instrumentation only** and does not alter Livewire behavior, routes, permissions,
or workflow transitions.

If an earlier `before` JSONL contains `initial_get` but no `livewire_update`,
keep it as historical data and collect a new baseline with the corrected code.
Use `FLOWTRACK_ORDER_BASELINE_RUN_ID=before_livewire_fixed` for clean separation,
then regenerate a report filtering on that new run ID. Do not compare this
mixed/incomplete old run with optimized after-phase timings.
