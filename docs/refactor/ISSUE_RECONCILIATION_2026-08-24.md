# FlowTrack issue reconciliation — 2026-08-24

## Input

This pass starts from `Archive 3(20260824-073746).zip` and targets the PHPUnit run that reported **35 failures / 300 passes (2823 assertions)**.

## Application defects fixed

1. **Order workflow runtime mirror / SQLite FK**
   - `OrderWorkflowSetupService::initializeWorkflowTemplate()` now creates the legacy runtime `workflows` mirror before inserting the seven `workflow_phases`.
   - This satisfies the non-null `workflow_phases.workflow_id` foreign key and fixes the common failure root affecting JobSelection and SafeSetup tests.

2. **Create Order workflow precedence**
   - Create Order remains Order-workflow-only.
   - A matching client-specific Order workflow is preferred over the generic all-client default.
   - Workflow option hydration follows the same ordering.

3. **Dashboard shipping KPI**
   - The Dashboard summary now derives shipping orders from either `workflow_phase_id` or `source_workflow_phase_id` and recognizes shipping phase names/short names.

4. **Orders shared DateRange behavior**
   - `updatedDateFrom()` and `updatedDateTo()` now activate the shared date-range filter exclusively through `clearListFiltersExcept('dateRange')`.

5. **Order owner inline-save feedback**
   - The Order detail header now renders the shared `x-ui.inline-save-state` next to the owner editor.

6. **Reviewed query debt**
   - Three full `->get()` calls in `AdminService` were converted to cursor/lazy collection forms without changing the returned collection contract.
   - Phase 0 `app_get_calls` is back to its frozen ceiling of 309.

7. **Release CI boundary**
   - Added `.github/workflows/flowtrack-ci.yml` with install, Pint, PHPUnit, Phase 15 quality, Vite build, bundle, dependency audit, visual, and release checks.

## Test-contract reconciliation

Tests that still asserted obsolete implementation locations were updated to follow the current decomposed architecture, including:

- current Order-workflow-only Create Order semantics;
- runtime Workflow mirror creation in Board cache fixtures;
- union-type-safe reflection for Document Archive user parameters;
- extracted Order detail stage-card ownership;
- Phase 5 Action/Query boundaries;
- current Shipping details atomic update boundary;
- current Order owner label/accessibility contract;
- current Inquiry detail/taskflow component ownership;
- parameterized supplier fallback text;
- current rich-text editor reference and whitespace-safe CSS assertion;
- parent status display ownership in the extracted Order summary.

No legacy CSS, `flowtrack.css`, or monolithic service/view implementation was restored to satisfy these tests.

## Governance reconciled

Several quality fingerprints/inventories were older than the supplied post-refactor source. They were reconciled with explicit `approved_later_phase_updates` metadata rather than weakening structural checks:

- Phase 5 Orders markup fingerprint for the shared DateRange restoration;
- Phase 6 Inquiry extracted markup fingerprints;
- Phase 7 setup-service hashes, including the runtime-mirror FK fix;
- Phase 8 Dashboard compatibility hash for the shipping KPI fix;
- Phase 11 query inventory line/source reconciliation.

## Validation in this environment

- `bash scripts/quality/php-lint.sh` — PASS (640 PHP files)
- `npm run quality:phase15` — PASS end-to-end
  - Phase 0 architecture
  - Phase 1 CSS foundation
  - Phase 2 UI components
  - Phase 3 migration + CSS modularization
  - Phase 4 shared filters
  - Phase 5 Orders
  - Phase 6 Inquiries
  - Phase 7 administration
  - Phase 8 application boundaries
  - Phase 9 security
  - Phase 10 document security
  - Phase 11 performance
  - Phase 12 dashboard/read models
  - Phase 13 JavaScript/realtime
  - Phase 14 infrastructure
  - final security review
  - Phase 15 release hardening

The uploaded source does not contain `vendor/` or `node_modules/`, so a fresh dependency-backed `php artisan test` and `npm run build` cannot be claimed from this environment. The user should run those two commands locally on the packaged source.
