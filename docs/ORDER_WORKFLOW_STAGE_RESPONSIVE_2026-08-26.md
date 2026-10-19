# Order workflow stage responsive fix — 2026-08-26

## Scope
Updated the reusable workflow-stage filter used on the Orders list so it responds to the actual content/container width, including layouts where the persistent sidebar reduces available space.

## Changes
- Replaced the visual `✓ Selected` badge with a compact tick-only circular indicator.
- Kept `aria-pressed` on stage buttons so selection remains accessible without the visible word `Selected`.
- Added CSS container queries to wrap stage cards based on real available width rather than viewport width only.
- Stage layout now adapts to 7 columns on wide content, 4/3/2/1 columns as the component narrows.
- Added width/min-width protections to prevent card and strip overflow.
- Updated the active compiled shared-components asset and Vite manifest so the fix works immediately after deployment without requiring a local frontend rebuild.

## Main files
- `resources/views/components/orders/workflow-stage-overview.blade.php`
- `resources/css/components/order-workflow-stage-overview.css`
- `public/build/manifest.json`
- `public/build/assets/shared-components-stage-responsive-826.css`
