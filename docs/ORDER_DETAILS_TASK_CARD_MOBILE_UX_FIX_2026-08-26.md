# Order Details task card responsive UX fix — 2026-08-26

Updated the Order Details `Order process & tasks` section for small and medium content widths.

## Fixes
- Prevents task titles/descriptions from breaking letter-by-letter.
- Uses full card width for task information.
- Moves the task status icon to a compact top-right indicator on smaller screens.
- Uses a two-column Assignee/Due Date metadata row on tablet widths.
- Stacks Assignee, Due Date, Status/Files and Action cleanly on phones.
- Makes the primary task action full-width on phones.
- Keeps stage cards horizontally scrollable instead of compressing them.
- Preserves all existing Livewire actions, permissions, workflow logic and inline editors.

## Files
- `resources/css/modules/orders/detail/responsive-task-cards.css`
- `resources/css/modules/orders/detail-prototype.css`
- `public/build/assets/index-order-responsive-826b.css`
