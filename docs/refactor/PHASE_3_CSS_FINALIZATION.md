# Phase 3 Finalization — Modular CSS and Legacy Removal

## Outcome

The CSS migration is now structurally complete at the source-ownership level. The previous giant stylesheet and compatibility directories are removed rather than merely relocated behind legacy loaders.

Removed source paths:

- `resources/css/flowtrack.css`
- `resources/css/legacy/`
- `resources/css/migration/`

The authenticated application now composes CSS from `foundation/`, `components/`, `application/`, and feature-owned files under `modules/`.

## Before / after

| Metric | Before | After |
|---|---:|---:|
| `flowtrack.css` | 754,383 bytes / 36,459 lines | removed |
| `legacy/compatibility` | 24 files / 991,126 bytes / 18,773 lines | removed |
| Largest source CSS file | 754,383 bytes | 89,643 bytes |
| Source CSS files over 100 KB | multiple | 0 |
| `resources/css/legacy` | active | absent |
| `resources/css/migration` | active | absent |

## New source ownership

```text
resources/css/
├── app.css
├── application/
│   ├── core.css
│   ├── prelude.css
│   ├── after-core.css
│   ├── after-dashboard.css
│   └── shared-components.css
├── foundation/
├── components/
├── modules/
│   ├── application/
│   ├── admin/
│   ├── catalog/
│   ├── clients/
│   ├── dashboard/
│   ├── documents/
│   ├── inquiries/
│   ├── orders/
│   ├── reports/
│   ├── setup/
│   ├── tasks/
│   └── work/
└── utilities.css
```

`application/*.css` files are composition-only. They exist only to preserve historical cascade boundaries while the actual selectors live in component/module files.

The former `flowtrack.css` source was split into 19 bounded application segments. Feature-specific compatibility files were moved into their real owners, for example:

- Order create → `modules/orders/create.css`
- Order finance → `modules/orders/finance.css`
- Order documents → `modules/orders/document-upload.css`
- Inquiry core → `modules/inquiries/core/`
- Inquiry Intelligence → `modules/reports/inquiry-intelligence.css`
- Documents Archive → `modules/documents/archive.css`
- My Work → `modules/work/my-work.css`
- Master Data → `modules/setup/master-data/`
- Product Categories → `modules/catalog/product-categories.css`
- User Editor → `modules/admin/user-editor.css`
- Task attachments → `modules/tasks/detail-attachments.css`

## Cascade preservation

This migration intentionally preserves stylesheet order. The authenticated layout loads:

1. `application/prelude.css`
2. `app.css`
3. `application/after-core.css`
4. Dashboard prototype only on Dashboard/Team Performance routes
5. `application/after-dashboard.css`
6. `application/shared-components.css`
7. narrow route modules
8. the centralized theme package

No visual redesign was intentionally performed by this migration.

## Equivalence checks

The source migration was checked against the uploaded pre-finalization archive:

- former `flowtrack.css`: exact byte-for-byte reconstruction from the 18 source-preserving segments — **PASS**;
- 21 directly relocated compatibility files: byte-identical — **PASS**;
- Inquiry, Master Data, and Order Detail prototype oversized files: top-level CSS parse/serialization equivalence after splitting — **PASS**;
- all current `resources/css/**/*.css`: parser check — **PASS**;
- unresolved local `@import`: 0;
- CSS source files larger than 100 KB: 0.

## Governance

New quality command:

```bash
npm run quality:css-modularization
```

It fails when:

- `flowtrack.css` returns;
- `resources/css/legacy` returns;
- `resources/css/migration` returns;
- a `legacy/compatibility` import is introduced;
- a CSS import target is missing;
- a composition entry contains selectors;
- any source CSS file exceeds 100 KB;
- aggregate source-preserved `!important` / hard-coded-color debt grows.

`quality:phase3` now includes this gate, so later phase quality chains inherit the rule.

## Build note

The source archive does not include `node_modules`, so a fresh Vite production build could not be executed in this environment. The existing `public/build` manifest was remapped to the equivalent pre-finalization compiled assets so the extracted project can still resolve Vite entries before the next local build. Run `npm ci && npm run build` in the dependency-complete development environment before release.
