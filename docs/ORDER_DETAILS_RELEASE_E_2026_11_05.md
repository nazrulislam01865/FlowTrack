# FlowTracker Order Details — Release E: final verification and release gate

**Scope:** Phase 5 of the approved five-phase plan. This is a **verification-only release**. No controllers, models, services, Livewire components, JavaScript runtime, Blade views, migrations, authorization scopes, task semantics, queue handlers or default feature flags were changed.

Release E adds a strict **read-only** benchmark gate, deterministic diagnostic tests, a single local regression-test runner and a manual staging sign-off checklist. It intentionally **does not self-approve** an untested rollout.

## Deliverables

- `scripts/order-release-e-gate.py` — matches the same order, same user, same isolated HTTP section, and same run ID in two captured JSONL runs. Produces a Markdown performance-and-acceptance report with PASS / FAIL / INCOMPLETE statuses. Does not assume nested service spans are additive or treat bundled Livewire updates as an isolated section.
- `scripts/tests/test_order_release_e_gate.py` — regression tests for missing samples, mismatched users/order IDs, SQL and latency regression, 500-query workflow spikes, failed requests, bundled Livewire calls and manual evidence.
- `scripts/order-release-e-tests.sh` — runs existing Releases A–D Laravel tests, JavaScript routing/reconnect tests and Python diagnostic tests. Exits if a test fails.
- `docs/ORDER_DETAILS_RELEASE_E_MANUAL_CHECKLIST.example.json` — copy and complete using genuine staging/browser observations. The gate requires nonblank reviewer/environment/date and an explicit passing result **with evidence** for every scenario.
- `docs/ORDER_DETAILS_RELEASE_E_ARCHIVED_ASSESSMENT.md` — a diagnostic assessment of the source ZIP's archived `AB_B0` and `AB_B1` logs; **not** a C/D before-and-after result or production sign-off.

## 1. Install and run local automated regression tests

Back up the original checkout, then overlay the changed-files ZIP in the FlowTracker project root. Run:

```bash
php artisan optimize:clear
sh scripts/order-release-e-tests.sh
npm ci
npm run build
npm audit
npm audit --omit=dev
```

`npm audit` warnings must be reviewed. Do not use `npm audit fix --force` without evaluating dependency compatibility. The project test settings should be verified as isolated before executing tests (`phpunit.xml` has SQLite `:memory:`); do not test against a production database. Record passed test names/counts and output in the manual checklist. This source ZIP does not contain `vendor/` or a running application database, so Laravel tests cannot be executed in the packaging environment.

## 2. Capture a proper Release E before/after baseline

Use a disposable local/staging database and **at least three distinct, visible** representative orders: a healthy recent order, a large order, and a historical/repair-prone order. The same authorized user, roles/workspace, order contents, feature flags, warmed/cold cache setup, and comparable host/load must be used for BOTH runs. Restore the same database snapshot if opening a workflow can mutate it.

**Important:** Release C makes unconditional PHP read-query changes, so it has no on/off switch. To compare **C against pre-C**, measure a pre-C checkout (Release A/B) against the new C/D checkout with the targeted realtime flag held **off** in both for the isolated request comparison. Do not label two runs of identical C/D code as proof of C's improvement.

**Before checkout (`E_C_BEFORE`):**

```dotenv
FLOWTRACK_ORDER_BASELINE_ENABLED=true
FLOWTRACK_ORDER_BASELINE_RUN_ID=E_C_BEFORE
FLOWTRACK_ORDER_TARGETED_EVENTS_ENABLED=false
```

Keep the same Release B blocker flag and existing workflow-summary / maintenance flags in both code checkouts. Then run `php artisan optimize:clear`, start `php artisan serve` (restart after each `.env` change), sign in as the test user, and visit **each** `/orders?open=ORDER_ID` at least five times. For each visit, let Products, Workflow, Attachments and Activity load. Avoid navigation that never opens the Order Details view. Preserve a browser network waterfall separately.

**After checkout (`E_C_AFTER`):** Restore the same disposable data state, deploy Release C/D code, use the same settings except `FLOWTRACK_ORDER_BASELINE_RUN_ID=E_C_AFTER`, clear config and restart. Repeat identical requests at least five times per order, preferably more for stable p95 estimates. Do not combine results from different user IDs, unlike historical `AB_B0` samples.

**Generate the server baseline reports:**

```bash
python3 scripts/order-baseline-report.py \
  --input storage/logs/order-details-baseline.jsonl \
  --run-id E_C_BEFORE --output storage/logs/order-e-before.md

python3 scripts/order-baseline-report.py \
  --input storage/logs/order-details-baseline.jsonl \
  --run-id E_C_AFTER --output storage/logs/order-e-after.md
```

## 3. Realtime validation (Release D) is separate

Build the Vite assets (`npm run build`) and enable only on disposable local/staging:

```dotenv
FLOWTRACK_ORDER_TARGETED_EVENTS_ENABLED=true
```

Run `php artisan optimize:clear` and hard reload. With **two authorized sessions**, inspect DevTools Network requests:

1. Modify Order B while Order A is open: expect **zero Order A targeted Livewire refreshes**.
2. Change Order A's task, product, attachment and activity: expect the appropriate summary and already-ready sections to refresh with correct data.
3. Remove access to Order A while a second user has it open: previously loaded protected data must not remain usable, and server-side actions must deny access.
4. Keep some sections unloaded: they must not issue refresh POSTs before becoming ready.
5. Test owner/member changes, unknown or missing order IDs, browser reconnect/version drift, supplier callbacks and retry behavior; legacy fallback must preserve updates.
6. Verify workflow hold/release, conditional tasks, documents/links, stage advancement, Redo, visible summary data, and rendered UI parity in different roles/browsers.

Compare targeted flag OFF vs ON using the same events; **server JSONL alone cannot determine the number of unnecessary browser dispatches**. Save DevTools screenshots/waterfall counts and staging outcomes as manual checklist evidence.

## 4. Execute the Release E verification gate

Copy `docs/ORDER_DETAILS_RELEASE_E_MANUAL_CHECKLIST.example.json` to a **local, uncommitted** file, for example `storage/logs/order-e-manual.json`. Fill real reviewer, environment, date and the `checks` entries (`passed: true` and a concise description of observed evidence). Do not set any result to true unless observed. Leave unknown results as `null`, and failed checks as `false` with supporting evidence.

```bash
python3 scripts/order-release-e-gate.py \
  --input storage/logs/order-details-baseline.jsonl \
  --before E_C_BEFORE \
  --after E_C_AFTER \
  --orders ORDER_ID_SMALL ORDER_ID_LARGE ORDER_ID_HISTORICAL \
  --manual storage/logs/order-e-manual.json \
  --output storage/logs/order-release-e-gate.md
```

Replace order ID placeholders with integers. The CLI returns **0** for PASS, **1** for FAIL, and **2** for INCOMPLETE; the Markdown report is written in all three cases. Missing a run, having fewer than five same-user samples per order *and* section, failing HTTP events, a SQL-count regression, or poor p95 performance prevents sign-off. The default conservative tolerances are: no increase in SQL p50, no more than 20% increase in median DB time and p95 request latency (adjustable, but changes must be documented). A workflow request with 500 or more SQL queries fails the gate.

**Be careful:** the gate cannot verify equivalent task status, cache heat, concurrency, role changes or correctness by itself; the manual checklist and regression runs cover these. A passing gate does not replace a controlled deployment or post-release monitoring.

## 5. Release criteria and rollback

| Area | Required evidence | If not satisfied |
|---|---|---|
| Automated correctness | All focused Laravel, JS and Python suites pass; no unexpected HTTP errors | Do not deploy |
| SQL performance | 3 representative orders; 5+ matching samples in each section/side; no SQL p50 regression or 500+ Workflow-query incident | Incomplete/failed gate; diagnose |
| Response time | DB p50 and response p95 within specified thresholds and comparable runs | Repeat/diagnose before sign-off |
| Realtime | No wrong-order refresh, correct owner/member reauthorization, loaded-section targeting, reconnect/fallback verified | Turn targeted events off |
| UI and workflow | Identical workflow eligibility, auto-advance, repair, permissions, supplier updates and visible UI | Revert affected change |
| Security/build | `npm ci`, `npm run build`, high-severity audit reviewed, correct Vite assets deployed | Hold deployment |

**Rollback switches (only if enabled previously):**

```dotenv
FLOWTRACK_ORDER_TARGETED_EVENTS_ENABLED=false
FLOWTRACK_WORKFLOW_AUTO_ADVANCE_BLOCKER_FAST_PATH_ENABLED=false
FLOWTRACK_WORKFLOW_MAINTENANCE_BATCH_ENABLED=false
FLOWTRACK_WORKFLOW_SUMMARY_EVIDENCE_EXISTS_ENABLED=false
FLOWTRACK_ORDER_BASELINE_ENABLED=false
```

Do **not** blindly overwrite production `.env` to these settings. Disable only the optimization implicated by the regression, retain your other intended configuration, run `php artisan optimize:clear`, restart long-lived workers only if their deployed configuration changed, and hard-refresh browser assets.

For unconditional Release C changes, restore the backed-up versions of `app/Services/OrderRedoService.php`, `app/Services/Orders/OrderWorkflowSummaryService.php`, and `app/Providers/AppServiceProvider.php`. For Release D, the targeted flag restores legacy routing without changing JS runtime; if fully reverting files, deploy the matching pre-release Vite build and manifest together. For unconditional Release B deduplication changes, restore the backed-up workflow binding/section files; disabling the B fast-path flag alone does not undo those query changes.

No migrations were added by Releases A–E. Keep workflow data and user access scopes intact; do not roll back database state solely to undo read-path code.

## Recorded archive outcome

The provided `Archive 5(20261104-181926).zip` contains `AB_B0` and `AB_B1` performance logs. These are **Release B** tests, not matched Release C before/after data. The archived B0 log also includes Order 333 observations for two user IDs (1 and 58); B1 observations use user 58. The new gate intentionally marks mixed-user groups **INCOMPLETE** rather than treating their SQL difference as clean causal evidence. See the archived assessment for exact details. No Release E live browser or staging/database sign-off is claimed.
