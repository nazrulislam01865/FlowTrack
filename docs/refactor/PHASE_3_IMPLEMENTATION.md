# Phase 3 — Incremental legacy CSS and page migration

## Status

Implemented as deployable migration batches on 20 August 2026. This phase preserves current application behavior and the Phase 1/2 design-system contracts while reducing fragmented CSS ownership.

## Batch 3A — Public CSS source migration

All legacy `/public/css/*.css` stylesheets were moved under `resources/css/legacy/compatibility/`. The layout no longer links directly to unmanaged CSS files. Their historical cascade order is preserved through three Vite-managed compatibility entries:

- `resources/css/legacy/prelude.css` — inline-editing CSS that historically loaded before the generated FlowTrack chunks;
- `resources/css/legacy/shell-a.css` — post-app shell compatibility styles that historically loaded before the Dashboard prototype;
- `resources/css/legacy/shell-b.css` — the remaining authenticated-shell compatibility styles.

The Dashboard prototype remains route-scoped through `resources/css/modules/dashboard/legacy-prototype.css`, matching its previous conditional loading behavior.

This is intentionally a compatibility migration, not a visual rewrite. Deletion/normalization of the moved legacy selectors continues in future component/page batches.

## Batch 3B — Blade style-block extraction

Application Blade style blocks were removed from:

- Orders list (`components/jobs/table.blade.php`);
- All Tasks (`livewire/board/index.blade.php`);
- Summary card component;
- Date range filter component;
- List export period modal.

The styles now live in explicit component/module files and are loaded after historical compatibility CSS where required to preserve cascade behavior.

The Laravel starter `welcome.blade.php` fallback style is outside the authenticated FlowTrack application and is the only remaining Blade `<style>` block.

## Batch 3C — Orders / All Tasks module ownership

Route-scoped Vite entries now own the extracted page layouts:

```text
resources/css/modules/orders/
├── index.css
└── list.css

resources/css/modules/work/
├── index.css
└── all-tasks.css
```

These entries are loaded only for `jobs.index` and `all-tasks`, so a regression can be reverted independently without removing the design-system foundation.

## Batch 3D — Setup, dashboard and shared inline cleanup

Repeated static inline declarations were replaced with semantic classes for:

- Workflow delete-impact modal;
- Task Pack delete-impact modal;
- disabled prototype-only Task Pack controls;
- Dashboard panel spacing and fixed column widths;
- Client summary-card column count;
- Client logo optional caption;
- Inquiry Taskflow add button height;
- report 100% progress track;
- Order/Inquiry activity moderation controls;
- Order task/order flag pill geometry.

Runtime Master Data colors and dynamic progress geometry remain inline CSS custom properties/width values where data-driven styling is required by the design-system exception.

## Vite structure

The four generated `flowtrack-01..04.css` chunks remain because `flowtrack.css` is still a major compatibility source. Phase 3 does **not** delete that mechanism prematurely. Vite now additionally owns the migrated legacy bundles and route-specific module entries.

## Governance

Run:

```bash
npm run quality:phase3
```

The Phase 3 gate enforces:

- zero CSS files under `public/css`;
- zero direct `/css/*.css` links in Blade;
- zero `<style>` blocks in authenticated/application Blade views;
- a non-increasing inline-style ceiling;
- non-increasing hard-coded-color / `!important` / byte budgets for extracted legacy CSS;
- required route/module asset entries remain present.

## Rollback

Each batch can be reversed independently:

1. restore the affected Blade style block or old public CSS link;
2. restore the corresponding compatibility asset location/order;
3. regenerate Vite assets;
4. run Phase 0–3 gates and affected visual/feature regression checks.

No database or business-logic rollback is required.

## Remaining legacy debt

This phase deliberately does not pretend that `flowtrack.css`, the large Inquiry stylesheet, Master Data stylesheet, or feature-specific compatibility CSS have been fully normalized. They are now source-managed and frozen behind measurable boundaries. Future Phase 3 migration batches can convert their selectors to official components and delete them safely.
