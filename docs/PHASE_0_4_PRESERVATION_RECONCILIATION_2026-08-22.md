# Phase 0-4 Preservation Reconciliation - 2026-08-22

## Objective

Preserve the previously completed Phase 0-4 refactoring foundation after the later Order, Order Workflow, and setup-screen changes, without changing FlowTrack business rules, workflow transitions, routes, database schema, or service behavior.

## What was reconciled

1. **Phase 1 frozen CSS foundation restored**
   - Removed the Aug-22 Task Pack task-color rules from the frozen `resources/css/flowtrack.css` compatibility source.
   - The rules were preserved unchanged inside the setup compatibility layer.
   - `flowtrack.css` is back below/equal to its original Phase 1 ceilings.

2. **Approved prototype CSS isolated from canonical design-system debt**
   - Order Detail prototype CSS moved unchanged from `resources/css/modules/orders/detail.css` to `resources/css/legacy/compatibility/order-detail-prototype.css`.
   - Order Workflow Setup prototype CSS was separated from the tokenized setup foundation into `resources/css/legacy/compatibility/order-workflow-setup-prototype.css`.
   - Route entry files still load the same CSS in the same logical order.
   - Both compatibility files have exact frozen non-increasing debt ceilings in `quality/phase3-inherited-exceptions.json`.

3. **Phase 4 selector contract restored**
   - `resources/views/components/jobs/order-detail/products.blade.php` now calls `x-ui.search-select` directly instead of the deprecated `x-ui.remote-select` wrapper.
   - The old wrapper already delegated to `x-ui.search-select` with the same properties, so selector behavior is unchanged.

4. **Test-contract source assertions repaired**
   - Six feature tests had double-quoted source assertions with unescaped PHP variables.
   - Only the assertion string escaping was changed; application logic was not changed.

5. **Original baselines preserved**
   - `quality/architecture-baseline.json` was not rewritten.
   - Later feature growth that belongs to future roadmap phases is recorded in `quality/architecture-inherited-exceptions.json` as an exact non-increasing ceiling.
   - If any frozen value grows further, the architecture gate fails again.

## Frozen future-phase debt

These items were intentionally **not** deeply refactored in this preservation pass because doing so would begin later roadmap phases and increase regression risk:

- `app/Livewire/Jobs/Index.php` -> Phase 5
- `app/Livewire/Inquiries/Index.php` -> Phase 6
- `app/Livewire/MasterData/Index.php` -> Phase 7
- `app/Services/InquiryService.php` -> Phase 8
- `app/Services/JobService.php` -> Phase 5 / Phase 8
- Blade `@php` preparation debt -> Phases 5-7
- application `->get()` review -> Phase 11

The exceptions do not replace the Phase 0 baseline; they prevent additional growth until the owning phase removes them.

## Verification completed

- `npm run quality:phase4` -> PASS
- Phase 1 CSS foundation gate -> PASS
- Phase 2 UI component library gate -> PASS
- Phase 3 migration gate -> PASS with explicitly frozen inherited exceptions
- Phase 4 shared forms/filter/search gate -> PASS
- Test/fixture contract gate -> PASS
- PHP lint -> 416 files PASS
- `node --check scripts/split-flowtrack-css.mjs` -> PASS
- `node --check vite.config.js` -> PASS
- CSS import target validation -> PASS
- Original Order Detail CSS vs relocated compatibility file SHA-256 -> identical
- Original `app/` directory vs reconciled `app/` directory -> no differences

## Environment-dependent checks still required before production release

The supplied archive does not contain `vendor/` or `node_modules/`. Therefore this preservation package cannot truthfully execute the full PHPUnit application suite or a production Vite build in this isolated archive. Run those two checks in the normal dependency-complete development/deployment environment before production deployment.

## Safety conclusion

This pass restores and enforces the Phase 0-4 architecture boundaries while preserving the current functional implementation. It deliberately avoids decomposing business coordinators/services until their roadmap phases, so the Order workflow and other current functionality are not structurally rewritten as part of this reconciliation.
