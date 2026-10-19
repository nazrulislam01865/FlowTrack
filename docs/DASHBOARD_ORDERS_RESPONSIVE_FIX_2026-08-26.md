# Dashboard & Orders Responsive Fix — 2026-08-26

## Problem fixed
The application switched the sidebar to mobile mode only at 900px, while Dashboard and Orders still used layouts sized for the full viewport between roughly 901px and 1180px. On tablets and small laptops the persistent sidebar reduced the actual content width, causing filter controls, cards, and table regions to overflow or clip.

## Changes
- Added a dedicated 901–1180px constrained-content breakpoint for Dashboard.
- Dashboard filters now reflow instead of horizontally clipping.
- Dashboard action buttons wrap safely on small laptops/tablets.
- Priority-work tabs and tables stay inside the page; the table scrolls internally when needed.
- Workflow stage cards use 3 columns in the sidebar-constrained tablet range, 4/2/1 columns at other responsive widths as appropriate.
- Orders filters use 2 columns on tablet/small-laptop widths and 1 column on smaller mobile screens.
- Orders page actions, phase quick filters, date fields, and searchable dropdowns no longer force page-level horizontal overflow.
- Orders table remains a deliberate internal horizontal scroll area on narrow devices rather than widening the full page.
- Search-select dropdowns are capped to the viewport width.
- Updated the prebuilt Vite CSS assets and manifest, so the fix is included even before running a fresh Vite build.

## Source files changed
- `resources/theme/flowtrack/components/management-dashboard.css`
- `resources/css/components/order-workflow-stage-overview.css`
- `resources/css/modules/orders/list.css`

## Prebuilt frontend updated
- `public/build/manifest.json`
- `public/build/assets/theme-rsp826.css`
- `public/build/assets/shared-components-rsp826.css`
- `public/build/assets/index-rsp826.css`

No application/business logic was changed.
