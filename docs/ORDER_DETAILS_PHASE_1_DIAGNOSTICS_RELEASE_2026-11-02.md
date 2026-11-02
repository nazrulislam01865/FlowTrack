# FlowTracker - Order Details Phase 1 diagnostic release (02 Nov 2026)

## What this release does

This is **Phase 1 only**: opt-in measurement, classification and validation. It is **not** the Remote Area indexing fix (Phase 2), the Livewire request reduction (Phase 3), or the Workflow repair optimization (Phase 4). The default baseline flag remains **OFF**. No schema changes, UI changes, changes to master data, worker behavior, task transitions, shipping rules or PHP-FPM settings are included.

The existing Phase 1 collector is retained. This narrow patch repairs its HTTP event kind/subtype collision, keeps the October legacy JSONL readable, records a per-request correlation ID and PHP *process CPU time* (separate from elapsed time), times six expensive Order Details service boundaries, attaches their SQL count/time deltas from the existing query listener, and limits browser mutation-observer work. The generated report explicitly flags incomplete capture and 500+ SQL-query Workflow requests.

## Changed source files

- `app/Support/Performance/OrderBaselineRecorder.php`: safe event type + new opt-in service span timer.
- `app/Support/Performance/RequestPerformanceMonitor.php`: process CPU and query count/time snapshots, same existing middleware and SQL listener.
- `app/Services/OrderDetailViewService.php`: measure the two existing Remote Area lookup call sites; no change to their results.
- `app/Livewire/Jobs/OrderWorkflowSection.php`: measure sync, eligible artwork repair, auto-advance and detailed context build; exact original calls and order preserved.
- `scripts/order-baseline-report.py`: corrected new/legacy event grouping, span rows, coverage and large-query anomaly flag.
- `public/js/order-baseline.js`: coalesced DOM scans and bounded network-event count, diagnostics only.
- `tests/Unit/OrderBaselineRecorderTest.php` and `scripts/tests/test_order_baseline_report.py`: regression tests.

## Deploy (controlled staging first; operator action required)

1. Deploy **only the files listed above**, preserving their relative paths. Confirm no `.env`, database migration, frontend bundle, route, UI or worker config changes are part of this patch.
2. Verify application revision, PHP/Laravel, and permissions on `storage/logs`; compare environment cache store and FPM worker count to the production snapshot. Capture an exact rollback copy of every replaced file.
3. Keep `FLOWTRACK_ORDER_BASELINE_ENABLED=false` initially (the existing default). Deploy and verify ordinary Order Details loads and four lazy sections with flag OFF. Verify that no baseline log lines are written.
4. In an explicitly approved **staging** window, set these existing `.env` variables:

   ```dotenv
   FLOWTRACK_ORDER_BASELINE_ENABLED=true
   FLOWTRACK_ORDER_BASELINE_RUN_ID=before_20261102_complete
   ```

   Apply the project's approved Laravel configuration refresh procedure. If `config:cache` is normally used, rebuild the cache after changing `.env`; ensure long-lived queue workers use the same configuration after their normal safe restart. Do not change global SQL logging or increase worker counts for measurement.
5. Select small, medium and large authorized orders **for the same staging user who will open the browser**:

   ```bash
   php artisan flowtrack:order-baseline-samples --user=YOUR_STAGING_USER_ID --limit=500
   ```

6. For each representative order, perform **at least five cold-client and five warm-client runs**, using a hard GET to `/orders?open=ORDER_ID`, followed by explicit loading of Products, Workflow, Attachments, and Activity. Save the browser Network waterfall; after all sections load run `__flowOrderBaseline.print()` in DevTools. Save exports locally under a timestamped run ID. Test read-only interactions separately. Don't clear production shared caches or run destructive task actions on real orders.
7. Repeat normal and anomaly-triggering cases where authorized: remote match/miss, high task/document count, legacy artwork, restricted viewer. To measure supplier delivery and auto-advance, use **disposable staging orders**, and verify the actual worker exists; merely recording pending marks does not establish a delivery SLO.
8. Generate a report:

   ```bash
   python3 scripts/order-baseline-report.py \
     --input storage/logs/order-details-baseline.jsonl \
     --run-id before_20261102_complete \
     --output storage/logs/order-baseline-before-20261102-complete.md
   ```

9. Collect time-aligned Linux CPU/process and FPM queue/slowlog metrics, e.g. `uptime`, `ps -eo pid,comm,%cpu,%mem --sort=-%cpu | head -25`, and approved sampling of FPM logs, Nginx response times, Redis/DB and queue workers. Store timestamp and timezone, deployed commit, cache backend, host CPU count, test user role/workspace, cache state, and test order size with each run. SQL fingerprint hashes have no SQL text; map them to test-only database profiling if required.
10. **Disable** `FLOWTRACK_ORDER_BASELINE_ENABLED` and apply the approved configuration refresh. Verify JSONL no longer grows from normal Order Details traffic; restrict/rotate/archive diagnostic logs using the existing private log policy. No global performance logging is required.

## Event schema and interpretation

- `kind=http_request`, `request_kind=initial_get|livewire_update`: middleware wall elapsed time, PHP CPU ms (when supported), SQL count/time, peak process PHP memory, sanitized Order Details component/method descriptors. A Livewire POST containing multiple components is a *combined request*, not a duration per child.
- `kind=service_span`, `span=remote_area_match|workflow_sync|artwork_repair|workflow_auto_advance|workflow_detail_context`: wall elapsed, PHP CPU and SQL query count/time delta. Spans may overlap or nest; **never add span times to request totals**. They are not inclusive of all Blade rendering or pre-middleware bootstrap work.
- Existing queue, task action and supplier delivery event types remain unchanged. Browser FCP, CLS, request waterfall and painted/loaded sections remain *local-only browser exports*; the PHP log does not contain these browser metrics.
- `php_cpu_ms` is `getrusage()` process-local user + system CPU. It does **not** include database server CPU, frontend JS, queue workers, container throttling, or total host CPU. `null` means unavailable, not zero.
- Old `kind=initial_get|livewire_update` JSONL remains supported by the report; these historic files are **not equivalent** to a new complete captured run.

## Phase 1 acceptance / exit gate (must be verified with real staging evidence)

- [ ] Identical permission/role and workspace for sampler and browser; at least three distinguishable order sizes plus remote/miss/legacy/restricted edge cases.
- [ ] Five cold and five warm repetitions per selected order; before-run version/timestamp and server/cache conditions recorded.
- [ ] `http_request` with `request_kind=initial_get` AND `request_kind=livewire_update` are present with the correct component/method names, even with hashed Livewire route.
- [ ] `service_span` rows present for the matcher and the Workflow sequence in requests that executed them; no sensitive request bodies/SQL bindings/files/tokens in JSONL.
- [ ] CPU/request, elapsed time, SQL count/time, memory, P50/P95 and 4xx/5xx results documented. Browser readiness measured separately. No misleading inferred CPU metrics.
- [ ] 603-618-query historical Workflow anomaly has a reproducible new trace **or remains explicitly unresolved with a named owner**. No unverified cause asserted.
- [ ] Logging disabled after the run, access and retention controlled, no visible UI or shipping/workflow regression; sign-off recorded before Phase 2.

## Validation commands available without production mutations

```bash
php -l app/Support/Performance/OrderBaselineRecorder.php
php -l app/Support/Performance/RequestPerformanceMonitor.php
php -l app/Services/OrderDetailViewService.php
php -l app/Livewire/Jobs/OrderWorkflowSection.php
php -l tests/Unit/OrderBaselineRecorderTest.php
node --check public/js/order-baseline.js
python3 -m unittest discover -s scripts/tests -p 'test_order_baseline_report.py'
php artisan test --filter=OrderBaselineRecorderTest
```

The final Artisan test needs the project's installed Composer dependencies. The uploaded archive did not include `vendor/` or a configured staging database; therefore real HTTP, Livewire, Workflow/DB integration, queue and CPU exit-gate testing must be run in the target environment. No benchmark speedup is claimed by this Phase 1-only patch.

## Rollback

Restore the original eight files from the pre-deploy backup. Disable the two baseline flags using the existing configuration mechanism (`FLOWTRACK_ORDER_BASELINE_ENABLED=false`; the run ID is informational). Re-run HTTP and Workflow smoke tests with the flag OFF. Do not delete production data. The JSONL file contains test diagnostics only and can be retained or removed according to the private log retention policy.
