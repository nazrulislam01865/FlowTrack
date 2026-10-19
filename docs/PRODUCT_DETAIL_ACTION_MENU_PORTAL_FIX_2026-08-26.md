# Product detail action menu portal fix — 2026-08-26

## Scope
- Order Details → Products & quantities row actions
- Inquiry Details → Products & quantities row actions

## Problem
The three-dot action menu was rendered inside the horizontally scrollable product-table wrapper. CSS overflow rules caused the dropdown to be clipped below the row, forcing users to scroll vertically just to see Edit/Remove/Restore actions.

## Fix
- Reused the shared `catalog.detail-product-actions` component on both Order and Inquiry detail pages.
- Extended the shared component to support restore actions used by removed Order products.
- Teleported the dropdown to `body` with Alpine `x-teleport`.
- Positioned the menu with `position: fixed` using the trigger button's viewport coordinates.
- The menu automatically opens below the button when there is room and above it when the viewport bottom is too close.
- Added viewport edge clamping and closes on Escape, window scroll, and resize.
- Preserved existing Livewire Edit, Remove, Restore, confirmation, permissions, and loading behavior.

This keeps the table compact and avoids changing its horizontal-responsive behavior.
