# Order Details product action column layout fix - 2026-08-22

## Problem
The Products & quantities table could appear horizontally shifted or clipped when the Actions column contained the Edit or Restore button. The column was only 48px wide, while the real controls require substantially more width. Their visual overflow extended the scrollable table area and could leave the first Product column clipped when the browser moved the horizontal scroll position toward the focused action.

## Fix
- Reserved a stable 104px Actions column for both Edit and Restore controls.
- Rebalanced the remaining table columns so the table still fits the card.
- Added box-sizing containment to header and body cells.
- Prevented horizontal scrolling on wide desktop layouts where the table has enough room.
- Kept controlled horizontal scrolling for medium layouts and the existing card-style mobile layout at 680px and below.
- Reset the fixed action width in mobile mode so the action row remains full width.
- Made the Actions heading explicit and aligned it with the controls.
- Added a cache-busted compiled Orders CSS asset and updated the Vite manifest so the package works without requiring an immediate frontend rebuild.

## Behavior unchanged
No product, supplier, quantity, price, restore, edit, permission, or order workflow backend logic was changed.
