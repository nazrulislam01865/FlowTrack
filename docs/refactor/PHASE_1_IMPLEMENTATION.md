# Phase 1 — Central design tokens and global CSS foundation

## Implementation status

Implemented against the 20 August 2026 FlowTrack archive after the Phase 0 governance layer. The user explicitly requested progression to Phase 1 even though Phase 0 still records environment-dependent PHPUnit/visual/performance approvals as pending; those items remain pending and are not silently marked complete.

## Objective

Create one authoritative source for static FlowTrack design values and a safe global CSS foundation without redesigning pages or beginning the Phase 2 component migration.

## Delivered structure

```text
resources/css/
├── app.css
├── foundation/
│   ├── tokens.css
│   └── global.css
├── utilities.css
├── legacy/
│   └── historical-overrides.css
├── flowtrack.css
└── generated/flowtrack-01..04.css
```

`foundation/tokens.css` owns brand/semantic/neutral colors, semantic surface/text/border roles, typography, spacing, radius, shadows, motion, focus, z-index, control sizing, and the temporary legacy variable bridge.

`foundation/global.css` provides low-specificity token-driven box sizing, body/font defaults, focus-visible behavior, disabled cursor semantics, semantic typography roles, and reduced-motion behavior.

`app.css` is composition-only.

`legacy/historical-overrides.css` imports `flowtrack.css` last and preserves the one small selector that historically lived after the old app.css import.

The four generated CSS runtime entries remain intentionally. The splitter now recursively expands the local `app.css` import graph. Removing the split mechanism remains Phase 3 work.

## Governance added

`quality/css-legacy-baseline.json` freezes pre-Phase-1 legacy CSS byte, `!important`, and hard-coded-color ceilings, including direct public CSS assets.

`scripts/quality/css-foundation-budget.php` enforces legacy ceilings, prohibits new public CSS files, verifies app.css composition/import order, validates token naming/duplicates, rejects raw static colors and `!important` in managed foundation CSS, checks namespace ownership, and keeps the historical compatibility file bounded.

The Phase 0 architecture scanner now treats `tokens.css` as the one allowed static-color owner rather than counting it as hard-coded-color debt.

## CI integration

`.github/workflows/quality.yml` runs the CSS foundation gate with the existing PHP and frontend quality stages. Local combined gate:

```bash
npm run quality:phase1
```

## Explicitly not done in Phase 1

- no shared button/card/form/table component migration;
- no page-level CSS migration;
- no removal of direct public CSS files;
- no Livewire/service/business-logic refactor;
- no database, authorization, or security behavior change;
- no visual redesign;
- no deletion of the generated four-chunk compatibility mechanism.

## Rollback

No data rollback is required. Restore the pre-Phase-1 app.css/flowtrack.css/splitter/Vite config, remove the new foundation/governance files, regenerate the old four CSS chunks, and rebuild assets.

## Acceptance criteria

- [x] One authoritative static token source exists.
- [x] Brand, semantic, neutral, surface/text/border tokens exist.
- [x] Typography, spacing, radius, shadow, motion, focus, z-index and control scales exist.
- [x] Global focus-visible and reduced-motion defaults exist.
- [x] app.css is composition-only.
- [x] Explicit legacy boundary exists and loads last.
- [x] Legacy CSS debt ceilings are executable and non-increasing.
- [x] Existing generated-chunk runtime contract remains supported.
- [x] Token naming and permitted usage are documented.
- [x] No business logic or schema was changed.
- [ ] Authenticated visual baselines still require approval in the user's stable seeded environment.
- [ ] Full PHPUnit still requires a dependency-complete Composer `vendor/` environment, as Phase 0 recorded.
- [ ] Production Vite build still requires installed frontend dependencies; the implementation package itself does not include `node_modules`.
