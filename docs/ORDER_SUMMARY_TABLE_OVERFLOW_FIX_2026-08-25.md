# Order Summary Report table overflow fix

## Issue
Long Order/Material values and horizontal scrolling could visually bleed underneath the sticky Supplier/Warehouse columns. The original sticky cells used `background: inherit`, which can remain transparent, and the sticky offsets assumed exact column widths although the table was still auto-layout.

## Files changed

### `resources/views/livewire/reports/order-summary.blade.php`
- Added `ft-osr-table` class.
- Added a 14-column `<colgroup>` so every report column has a deterministic width.
- Removed inline `min-width` declarations from headers.
- Added `ft-osr-nowrap` to Order No., dates, and Quantity.
- Added `ft-osr-wrap` to Material.

### `resources/css/modules/reports/order-summary.css`
- Uses `table-layout: fixed` with the existing `1940px` report width.
- Adds exact column widths.
- Makes all cells overflow-safe.
- Keeps Material/Special Orders wrapping inside their own cells.
- Keeps Order No./dates/quantity on one line.
- Locks sticky Supplier to 130px and Warehouse to 120px so `left:130px` is always correct.
- Makes sticky body cells opaque for normal/urgent/overdue/completed rows and hover states, preventing underlying scrolled content from showing through.

### `public/build/assets/theme-k2GZdzSB.css`
- The same CSS patch is appended to the currently compiled theme asset so the fix works immediately without requiring a Vite rebuild.

## Result
The report design and data logic are unchanged. Horizontal scrolling remains available, Supplier/Warehouse remain sticky, and long entries stay inside their intended columns without overlapping or bleeding into neighboring cells.

## Deployment

```bash
php artisan view:clear
php artisan optimize:clear
```

No database migration is required.
