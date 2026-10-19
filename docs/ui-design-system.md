# FlowTrack UI Design System

## Status

**Phase 3 finalization complete.** This document is authoritative for static design tokens, global CSS ownership, reusable Blade/CSS component contracts, and the modular application stylesheet graph. The former `resources/css/flowtrack.css`, `resources/css/legacy/`, and `resources/css/migration/` sources have been removed.

The migration is source-preserving: existing visuals remain represented by owned component/module files while new work must use the token and component system. No new catch-all stylesheet is permitted.

## 1. Source ownership

```text
resources/css/
├── app.css                         # authenticated composition root only
├── application/                    # ordered composition entries/cascade boundaries
│   ├── core.css
│   ├── prelude.css
│   ├── after-core.css
│   ├── after-dashboard.css
│   └── shared-components.css
├── foundation/
│   ├── tokens.css                  # authoritative static visual values
│   └── global.css                  # low-specificity base/accessibility/type
├── components.css                  # official component composition root
├── components/                     # reusable shared UI styling
├── modules/
│   ├── application/                # source-preserving split of the former monolith
│   ├── orders/
│   ├── inquiries/
│   ├── dashboard/
│   ├── documents/
│   ├── clients/
│   ├── work/
│   ├── setup/
│   ├── catalog/
│   ├── reports/
│   ├── tasks/
│   └── admin/
└── utilities.css                   # approved narrow utilities only
```

`app.css` is the authenticated source composition root and is loaded directly by Vite. Phase 15 removed the former split generator and generated `flowtrack-01..04` source entries after verifying the expanded `app.css` graph was byte-equivalent to the old concatenated chunks.

## 2. Token naming contract

All new static design tokens use `--ft-<category>-<role-or-scale>`.

Examples: `--ft-color-primary-600`, `--ft-bg-surface`, `--ft-text-secondary`, `--ft-border-default`, `--ft-font-size-md`, `--ft-space-4`, `--ft-radius-md`, `--ft-shadow-lg`, `--ft-duration-normal`, `--ft-z-modal`.

Raw static design values belong in `foundation/tokens.css` only. New shared/module CSS consumes tokens rather than duplicating color, shadow, spacing, typography, radius, or transition values.

## 3. Token model

The token file owns primary blue, teal/accent, purple, success, warning, danger, and neutral scales plus semantic aliases for page/surface/text/border roles.

Prefer semantic aliases when meaning is known:

| Role | Token |
|---|---|
| Page background | `--ft-bg-page` |
| Surface | `--ft-bg-surface` |
| Subtle surface | `--ft-bg-surface-subtle` |
| Primary text | `--ft-text-primary` |
| Secondary text | `--ft-text-secondary` |
| Link | `--ft-text-link` |
| Default border | `--ft-border-default` |
| Strong border | `--ft-border-strong` |
| Focus border | `--ft-border-focus` |
| Brand primary | `--ft-color-brand-primary` |
| Primary hover | `--ft-color-brand-primary-hover` |

## 4. Typography

The controlled contract contains a font-family token, sizes `xs` through `4xl`, regular/medium/semibold/strong/bold/extrabold weights, line-height roles, and letter-spacing roles.

Phase 1 provides opt-in semantic type roles in `foundation/global.css`: `.ft-type-page-title`, `.ft-type-section-title`, `.ft-type-subsection-title`, `.ft-type-body`, `.ft-type-label`, `.ft-type-caption`, `.ft-type-helper`, and `.ft-link`.

## 5. Spacing, borders, radius and elevation

Use the approved `--ft-space-*` scale in shared CSS. Borders use centralized width/style/color roles. Radius uses `--ft-radius-*`; elevation uses `--ft-shadow-*`. Shared components must not invent local shadow/radius values.

## 6. Motion and accessibility

Global foundation behavior includes inherited form-control typography, keyboard `:focus-visible`, reduced-motion behavior, disabled/ARIA-disabled cursor semantics, and low-specificity rules inside the `ft-foundation` cascade layer.

Source-preserved module CSS remains unlayered where required for cascade parity. New component/module CSS should progressively replace those preserved rules without recreating a legacy directory or monolith.

## 7. Runtime Master Data colors

Workflow phases, statuses, priorities, flags and departments are runtime data, not static tokens. The approved migration contract is a validated runtime CSS custom property passed to a shared component. Static page/component design values must not use that exception.

## 8. Modular CSS finalization policy

The old CSS ownership boundary is gone. `resources/css/flowtrack.css`, `resources/css/legacy/`, and `resources/css/migration/` must remain absent. Existing pre-refactor styling has been split into owned component/module files while preserving source order.

Rules:

1. No catch-all `flowtrack.css` or equivalent monolithic stylesheet may be introduced.
2. No `legacy/`, `compatibility/`, or `migration/` CSS ownership directory may be introduced.
3. No source CSS file may exceed 100 KB; the ceiling is enforced by `scripts/quality/css-modularization.php`.
4. New shared styling belongs in `components/`; feature-only styling belongs in the narrowest `modules/<feature>/` owner.
5. `app.css` and application composition files remain import-only.
6. New direct stylesheets under `public/css` are prohibited.
7. Existing source-preserved `!important`/hard-coded-color debt is aggregate-frozen and may only shrink.
8. New global classes use `ft-`; approved narrow utilities use `u-`.
9. New `!important` is prohibited in managed foundation/shared component CSS.

Run `npm run quality:css-modularization` after CSS ownership changes.

## 9. Phase 1 compatibility aliases

The token root still exposes historical variable aliases (`--blue`, `--bg`, `--line`, etc.) for source-preserved module rules. They contain no independent static design values. New CSS must use the canonical `--ft-*`/theme contracts instead.

## 10. Required import order

`resources/css/app.css` remains composition-only:

```css
@import './foundation/tokens.css';
@import './foundation/global.css';
@import './components.css';
@import './utilities.css';
@import './application/core.css';
```

The authenticated layout preserves the former cascade boundaries with Vite-managed composition entries: `application/prelude.css`, `app.css`, `application/after-core.css`, the route-scoped Dashboard prototype when applicable, `application/after-dashboard.css`, and `application/shared-components.css`.

## 11. Preferred usage

```css
.ft-example-panel {
    padding: var(--ft-space-4);
    border: var(--ft-border-width-thin) var(--ft-border-style-default) var(--ft-border-default);
    border-radius: var(--ft-radius-xl);
    background: var(--ft-bg-surface);
    color: var(--ft-text-primary);
    box-shadow: var(--ft-shadow-sm);
}
```

## 12. Quality commands

```bash
npm run quality:architecture
npm run quality:css
npm run quality:phase1
npm run quality:components
npm run quality:phase2
npm run quality:css-modularization
npm run build
```

`quality:phase1` runs the Phase 0 architecture ceiling and Phase 1 CSS ownership gate. `quality:css-modularization` additionally prevents the removed monolith/legacy tree from returning and enforces the 100 KB source-file ceiling.

## 13. Change process

A new token requires a reusable semantic purpose, reuse of an existing equivalent token when possible, placement in the correct token group, documentation for a new category/contract, a passing Phase 1 gate, and visual comparison when an existing selector starts consuming it.

Phase 2 now consumes this foundation for the official buttons, badges, forms, dropdowns, cards, tables, tabs, modals, pagination, loading, empty-state, and validation contracts documented in `docs/ui-components.md`.


## 14. Phase 2 official component library

Phase 2 adds the official component contract documented in `docs/ui-components.md`. Component CSS is token-driven, contains no `!important`, and is isolated from legacy class-name collisions with `data-ft-ui-component` markers. New feature work should use `x-ui.*` components rather than writing page-level button, badge, card, field, table, tabs, modal, tooltip, pagination, loading, empty-state, or validation implementations.

`App\Support\MasterColor::style()` emits the official `--ft-dynamic-*` runtime-color variables while retaining the historical `--ft-master-*` bridge. This keeps Master Data color semantics compatible during incremental migration.


## Phase 3 migration boundary

Phase 3 introduced `resources/css/migration/` for extracted legacy component styles and `resources/css/modules/` for feature/page layout ownership. These are transitional source locations with explicit debt ceilings. New feature styling must not copy their legacy hard-coded values; new work continues to consume Phase 1 tokens and Phase 2 official components. Runtime Master Data colors and data-driven geometry remain the approved inline custom-property/geometry exception.

## Phase 4 interaction-system ownership

Searchable selects, multi-selects, filter bars, search fields, filter chips, reset actions, date ranges and their validation/loading/error states now have one shared visual and behavioral contract. Feature/module CSS may control placement only. It must not create another selector/dropdown visual implementation.

Large selector datasets are server-side, permission-scoped and bounded by `FilterOptionService::searchPage()`. Runtime query results are data, not design tokens. No-result and incomplete-search states must stay empty rather than returning arbitrary fallback options.
