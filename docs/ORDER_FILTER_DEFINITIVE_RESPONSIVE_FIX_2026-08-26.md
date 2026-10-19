# Orders filter definitive responsive fix — 2026-08-26

## Root cause
The responsive source rules existed, but the Orders route was still serving an older compiled CSS asset from `public/build`. The filter also relied too much on viewport breakpoints even though the sidebar can reduce the actual Orders content width.

## Fix
- Added container-based responsive layout to the Orders filter card.
- The filter now responds to the real available Orders-card width.
- Large content width: compact single-row layout.
- Medium content width: safe 4-column/two-row layout.
- Small laptop/tablet: 2-column layout.
- Mobile: 1-column layout with stacked date inputs.
- Forced date-range children to `min-width: 0` so native date inputs cannot expand the grid.
- Kept the mobile Orders list as labeled cards when the Orders card itself becomes narrow.
- Updated the actual compiled Orders CSS asset and changed its filename in the Vite manifest to force a browser cache miss.

## Files
- `resources/css/modules/orders/list.css`
- `resources/views/components/orders/list/table.blade.php` (mobile labels already retained)
- `public/build/assets/index-order-responsive-826b.css`
- `public/build/manifest.json`
