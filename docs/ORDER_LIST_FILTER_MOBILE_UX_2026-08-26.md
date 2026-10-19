# Order list filter + mobile UX fix (2026-08-26)

## Updated
- `resources/css/modules/orders/list.css`
- `resources/views/components/orders/list/table.blade.php`

## What changed
1. **Responsive filter toolbar**
   - Reworked the Orders filter bar to adapt to available content width instead of relying only on viewport width.
   - Search and date range now span better on medium screens.
   - On tablet and mobile, filters stack cleanly without clipping.
   - Clear button becomes full-width on smaller screens.

2. **Small-device list UX**
   - Converted the Orders table into a stacked card-style layout on small screens.
   - Each cell now shows a label using `data-label`, so mobile users can easily read row data.
   - Kept row colors/status styling and action links usable.
   - Reduced horizontal scrolling pressure on phones.

## Result
- The Orders filter bar no longer breaks on smaller laptop/tablet widths.
- The list view is much easier to use on mobile devices.
