# Phase 15 legacy exception register

Phase 15 removes compatibility code only when search evidence proves it has no executable caller. The machine-readable authority is `quality/phase15-legacy-exceptions.json`.

## Removed in Phase 15

- unused default `resources/views/welcome.blade.php`
- unused duplicate `resources/css/components/management-theme.css`
- deprecated broad-JavaScript compatibility bridge and `window.FlowTrack*` aliases
- `scripts/split-flowtrack-css.mjs`
- generated `resources/css/generated/flowtrack-01.css` through `flowtrack-04.css`

The authenticated layout now loads `resources/css/app.css` directly. Before deletion, the expanded app.css graph was verified byte-for-byte equal to the concatenated four generated Phase 14 chunks.

## Still active — shrinking-only

The `LegacyJobService`, `LegacyInquiryService`, and `LegacyDashboardService` implementations still have focused-service/facade callers. They are therefore retained until those call sites are migrated and regression-tested. New Legacy service callers are prohibited.

**CSS is no longer part of the legacy exception set.** Phase 3 finalization removed `resources/css/flowtrack.css`, `resources/css/legacy/`, and `resources/css/migration/`. Former compatibility rules now live under explicit component/module owners and are protected by `quality/css-finalization-manifest.json` plus `scripts/quality/css-modularization.php`. The preserved aggregate CSS debt may only shrink, and no single CSS source file may exceed 100 KB.
