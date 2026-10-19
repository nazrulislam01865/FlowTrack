# Cancelled Orders — Order/Action Columns No Longer Sticky

## User request
The first **Order** column and the last **Action** column must scroll normally with the rest of the cancelled-orders table. Neither column should stay fixed while horizontally scrolling.

## Files changed
- `resources/theme/flowtrack/theme.css`
- `public/build/assets/theme-k2GZdzSB.css`

## Source CSS change
The first and last table header/body cells now use `position: static` rather than `position: sticky`. The previous left/right offsets, z-index values, and sticky edge shadows were removed.

The red cancellation indicator on the first data cell remains unchanged.

## Result
- Order column scrolls horizontally with the table.
- Action column scrolls horizontally with the table.
- Horizontal scrolling remains available for the wide table.
- Column widths and table layout remain unchanged.
- Dynamic summary cards and cancelled-order data logic are unchanged.

## Deployment
Run:

```bash
php artisan view:clear
php artisan optimize:clear
```

No migration is required.
