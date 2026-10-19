# Order Details task-grid permanent stability fix — 2026-08-27

## Root cause
The Order Details workflow task row had multiple responsive CSS layers using named `grid-area` placement. In the same rules, later `grid-column: auto` / `grid-row: auto` declarations overrode parts of that placement. When the workflow card crossed container-query breakpoints, CSS Grid auto-placed task cells and the task table could collapse into a narrow left stack with large empty space.

## Fix
- Removed the conflicting `grid-column:auto` declarations that cancelled named task-grid areas.
- Added `resources/css/modules/orders/detail/permanent-task-grid.css` as the final Order Details style import.
- The final layer uses explicit row/column coordinates at desktop, medium-card, compact-card, and phone widths.
- Added a non-container-query fallback.
- Added a regression test that requires the stability layer to remain the final Order Details import and prevents the old conflicting declaration pattern from returning.
- Published the current Order Details bundle under a new manifest filename so browsers do not keep using a stale cached CSS asset.

## Scope
Presentation only. No workflow sequencing, task status, assignment, due date, permissions, documents, or workflow actions were changed.
