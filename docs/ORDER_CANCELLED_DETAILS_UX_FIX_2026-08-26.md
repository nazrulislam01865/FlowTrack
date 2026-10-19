# Cancelled Order Details UX Fix — 2026-08-26

## Problem
A rich cancellation reason (especially a pasted screenshot) was rendered directly inside the order header. This stretched the header to several screen heights. A second cancellation banner also rendered the stored rich-text marker/HTML as plain text, creating duplicate and broken cancellation information.

## Updated UX
- Cancelled orders now show a compact `Cancelled` status pill instead of the misleading `On Track` pill.
- The workflow stage pill is labelled as the **last stage** reached before cancellation.
- The full cancellation reason is moved into a dedicated compact history card below the order header.
- The card shows cancellation preview, cancelled-by user, date/time, last stage, and evidence image count.
- `View details` expands the original rich-text reason/evidence without changing the stored audit record.
- Pasted images are constrained inside the details panel and remain compatible with the existing rich-image viewer.
- The duplicate raw rich-text cancellation banner is removed.
- The disabled `Cancel order` button is replaced by a compact `Workflow locked` indicator once the order is cancelled.
- `Initiate Redo` remains the primary recovery action when available.

## Files
- `resources/views/components/jobs/order-detail/header.blade.php`
- `resources/views/components/jobs/order-detail/cancellation-card.blade.php` (new)
- `resources/css/modules/orders/detail/cancellation.css` (new)
- `resources/css/modules/orders/detail-prototype.css`
