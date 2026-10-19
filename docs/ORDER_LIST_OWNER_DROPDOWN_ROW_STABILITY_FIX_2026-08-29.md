# Order list owner dropdown row stability fix — 2026-08-29

## Problem

The Orders list **Owner** filter uses the shared searchable user picker with `fixed-menu="true"`. Fixed menus are teleported to `<body>` so they can escape toolbar/table overflow. After teleporting, the dropdown is no longer a descendant of `.ft-order-list-v5`, so Order-list-only avatar row rules do not apply to the menu itself.

The generic remote-filter layer then rendered the user option rows too tightly for the avatar plus the two text lines (name and role/department metadata). That caused adjacent users to visually overlap, as seen in the Owner dropdown.

## Fix

- The shared `x-ui.search-select` now marks avatar-based dropdown panels with the reusable `ft-search-select__menu--people` variant class.
- The menu also exposes its existing search context through `data-ft-search-select-context` for debugging and future contextual enhancements.
- The shared remote-filter CSS owns a stable people-row contract that still works after teleporting:
  - automatic row height with a 48px minimum;
  - 30px avatar with fixed flex sizing;
  - two-line user copy laid out vertically;
  - explicit line heights and ellipsis for long names/metadata;
  - selected-state text kept in its own non-shrinking column.
- The dropdown keeps the existing 280px maximum panel height and internal scrolling, so the fix does not make the menu grow across the page.

## Scope

This fixes the Orders **All owners** picker and stage-assignee user pickers. Because the behavior is implemented as a shared people-picker variant, other `x-ui.search-select` instances that intentionally render user avatars also receive the same row-stability protection.

No owner-filter query logic, Livewire commit behavior, user search endpoint, permissions, or selected values were changed.

## Built asset

The prebuilt `after-core` stylesheet referenced by `public/build/manifest.json` was refreshed with a new filename so deployed browsers do not reuse the stale dropdown CSS.
