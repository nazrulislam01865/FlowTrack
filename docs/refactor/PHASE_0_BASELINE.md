# Phase 0 — Baseline, safety net and engineering governance

**Project:** FlowTrack  
**Source snapshot:** Archive 5 (2026-08-20)  
**Roadmap phase:** Phase 0  
**Phase objective:** establish repeatable functional, visual, performance, bundle-size and architecture baselines before structural refactoring.

## 1. Phase status

| Area | Status | Evidence |
|---|---|---|
| Source architecture inventory | Complete | `quality/architecture-baseline.json` |
| Architecture debt gate | Complete | `scripts/quality/architecture-budget.php` |
| PHP source syntax baseline | Complete | 382 PHP files passed `php -l` |
| Frontend/source bundle baseline | Complete | `quality/frontend-bundle-baseline.json` |
| Historical performance baseline | Complete but provisional | `quality/performance-baseline.json` |
| Full PHPUnit baseline | Environment-blocked in this archive sandbox | `vendor/` is absent and Composer is unavailable in the execution environment |
| Approved authenticated screenshots | Harness complete; approval pending stable seeded runtime | `tests/Visual/visual-regression.mjs`, `quality/visual-scenarios.json` |
| CI quality-gate skeleton | Complete | `.github/workflows/quality.yml` |
| Backup/restore procedure | Complete | `docs/refactor/DATABASE_BACKUP_RESTORE.md` |
| Refactor branch/release procedure | Complete | `docs/refactor/REFACTOR_RELEASE_PROCEDURE.md` |
| Engineering governance | Complete | `docs/refactor/ENGINEERING_GOVERNANCE.md` |

**Acceptance interpretation:** Phase 0 tooling is implemented. Structural Phase 1 work should not begin until the full test suite has been executed in a dependency-complete environment and the first authenticated visual baseline set has been manually approved.

## 2. Source inventory baseline

The source snapshot matches the roadmap's current-state evidence.

| Metric | Baseline |
|---|---:|
| Blade files | 140 |
| Livewire classes | 25 |
| Services | 49 |
| PHP test files | 96 |
| `app/Livewire/Jobs/Index.php` | 3,981 lines |
| `app/Livewire/MasterData/Index.php` | 3,166 lines |
| `app/Livewire/Inquiries/Index.php` | 3,063 lines |
| `app/Services/InquiryService.php` | 2,630 lines |
| `app/Services/DashboardService.php` | 2,299 lines |
| `app/Services/JobService.php` | 2,012 lines |
| `resources/css/flowtrack.css` | 598,980 bytes / 7,355 lines |
| CSS `!important` scan, all current source CSS including generated chunks | 2,501 |
| Blade `@php` lines | 189 |
| Blade `app()` lines | 78 |
| Blade `auth()` lines | 91 |
| Blade `style=` lines | 157 |
| Blade `<style>` lines | 6 |
| Models with `protected $guarded = []` | 40 |
| Application lines containing `->get()` | 279 |

The architecture gate treats these debt metrics as **non-increasing ceilings**. File counts are informational because healthy modularization can increase the number of focused files.

## 3. Architecture budget policy

Run:

```bash
php scripts/quality/architecture-budget.php --check
```

The check fails when a tracked debt metric exceeds the approved Phase 0 baseline. The scanner intentionally uses the same line-based semantics for Blade `app()`, `auth()`, `style=` and `->get()` counts that produced the roadmap numbers.

Do not use `--write-baseline` to make a failing change pass. Re-baseline only after an approved debt reduction or scope change.

## 4. Functional safety-net status

### Completed in this Phase 0 execution

- PHP syntax lint passed for **382 PHP files** across application, bootstrap, config, database, routes and tests.
- Architecture debt scan passed against the newly recorded baseline.
- JavaScript Phase 0 scripts passed Node syntax checks.

### Full test-suite limitation

The archive does not include `vendor/`, and the execution environment used to prepare this Phase 0 package does not provide Composer or external package resolution. Therefore `php artisan test` could not be executed here without fabricating dependencies.

This is an **environment prerequisite**, not an application-test failure. The CI skeleton and release procedure contain the exact commands required to establish the authoritative PHPUnit baseline in the real repository/CI environment.

### Pre-existing runtime evidence in the archive

`storage/logs/laravel.log` contains two `jobs.index` HTTP 500 performance records from **20 August 2026 08:06 local log time**, accompanied by a Blade exception:

- `syntax error, unexpected token "endif"`
- view: `resources/views/components/jobs/detail-overview.blade.php`

The current Blade source in this archive has balanced simple `@if/@endif` and `@foreach/@endforeach` counts in the inspected file, so the historical log is recorded as a **pre-existing runtime incident**, not proof that the current snapshot still reproduces it. The full Laravel test/runtime pass must determine current status.

## 5. Performance baseline

The existing request monitor was reused; no business instrumentation was replaced.

Only three request-performance rows were available in the archived log because the current monitor logs slow/threshold-triggering requests rather than every request by default. Therefore this is a **provisional historical baseline**, not yet a representative p95 baseline.

Notable archived samples:

| Route | Status | Duration | Queries | Query time | Peak memory |
|---|---:|---:|---:|---:|---:|
| `jobs.index` | 500 | 1,222.92 ms max | 85 max | 68.11 ms max | 25.66 MB max |
| `default-livewire.update` | 200 | 1,797.25 ms | 1,515 | 996.65 ms | 6.00 MB |

Before performance-sensitive refactoring, run a stable representative session with:

```env
PERFORMANCE_MONITORING_ENABLED=true
PERFORMANCE_SAMPLE_RATE=1
PERFORMANCE_LOG_ALL_REQUESTS=true
```

Then parse the resulting log with:

```bash
php scripts/quality/performance-baseline.php
```

Capture Dashboard, Orders list/detail/task, Inquiries list/detail/task, My Work, Master Data, Product/Category, Clients, Documents and other busiest workflows using the same dataset and account.

## 6. Frontend and CSS transfer baseline

The archive contains an existing Vite production build, so source and compiled bundle sizes were recorded without rebuilding dependencies.

| Asset group | Raw bytes | Gzip bytes |
|---|---:|---:|
| All entries in current `public/build/manifest.json` | 580,025 | 100,548 |
| Source `resources/css/flowtrack.css` | 598,980 | 101,483 |
| Source `resources/css/components/management-theme.css` | 68,767 | 10,204 |
| Source `resources/js/app.js` | 29,184 | 7,223 |

The four generated FlowTrack CSS build entries remain the current canonical delivery mechanism for this snapshot. Phase 0 records them; it does not remove or redesign them.

## 7. Visual baseline system

`quality/visual-scenarios.json` defines desktop, tablet and mobile capture widths and currently covers:

- Dashboard
- Orders list
- My Work
- All Tasks
- Inquiries list
- Inquiry Intelligence
- Master Data
- Product Categories
- Products
- Clients
- Documents
- Workflow Setup
- Task Pack Setup

Order detail/task and Inquiry detail/task require stable seeded IDs. Phase 0 deliberately does not guess record IDs. Add those stable scenarios before migrating those feature families.

Commands:

```bash
npm ci --ignore-scripts
npx playwright install chromium

VISUAL_BASE_URL=http://127.0.0.1:8000 \
VISUAL_EMAIL=visual@example.com \
VISUAL_PASSWORD='local-test-password' \
npm run visual:update
```

Approved screenshots are committed under `tests/Visual/baselines`; actual/diff output is ignored.

## 8. CI quality-gate skeleton

`.github/workflows/quality.yml` now defines independent PHP and frontend jobs.

PHP gate:

1. Composer install
2. PHP source lint
3. Pint formatting check
4. Architecture debt budget
5. PHPUnit
6. Composer audit

Frontend gate:

1. `npm ci --ignore-scripts`
2. Vite production build
3. npm production dependency audit

Visual regression becomes a required CI job only after the first stable seeded baseline set is approved. Phase 0 does not auto-approve screenshots or invent credentials.

## 9. Governance and rollback

Phase 0 introduces no business logic, schema mutation, permission change or UI redesign. All additions are tooling, reports, test harnesses, CI scaffolding and governance documentation.

Rollback is therefore low risk: revert the Phase 0 tooling/documentation/package-script changes. No data rollback is required.

## 10. Phase 0 acceptance checklist

- [x] Current source architecture and debt budgets recorded.
- [x] Metrics can be re-run deterministically from source.
- [x] PHP syntax safety net executed.
- [x] Existing Vite bundle sizes recorded.
- [x] Existing performance-monitor data parsed and retained.
- [x] CI quality-gate skeleton added.
- [x] Visual-regression harness and scenario inventory added.
- [x] Database backup/restore procedure documented.
- [x] Refactor branch/release/rollback procedure documented.
- [x] Current source vs target-documentation discrepancy explicitly documented.
- [ ] Full PHPUnit suite executed in dependency-complete environment; all pre-existing failures classified.
- [ ] First authenticated visual baseline set captured and manually approved against stable seeded data.
- [ ] Representative performance run captured with all-request logging enabled.

**Gate to Phase 1:** complete the three unchecked environment-dependent acceptance items before changing the CSS architecture or starting structural source migration.
