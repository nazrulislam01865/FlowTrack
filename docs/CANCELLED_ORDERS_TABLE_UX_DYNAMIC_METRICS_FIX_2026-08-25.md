# Cancelled Orders table UX and dynamic metrics fix

Date: 2026-08-25

## Goal

Keep the approved Cancelled Orders design while fixing the broken table layout shown on the live page and making the four summary cards update from the same filtered data as the table.

## Files changed

- `app/Services/CancelledOrderService.php`
- `app/Livewire/Orders/CancelledOrders.php`
- `resources/views/livewire/orders/cancelled-orders.blade.php`
- `resources/theme/flowtrack/theme.css`
- `public/build/assets/theme-k2GZdzSB.css`
- `tests/Feature/CancelledOrdersPrototypeImplementationTest.php`

## Dynamic summary cards

`CancelledOrderService::metrics()` now accepts the active filters and starts from `filteredQuery($user, $filters)`. The cards therefore follow the same permission-scoped dataset as the table:

- Total Cancelled
- Cancelled This Month
- Most Common Reason
- Restorable

With no filters, the values represent all cancelled orders visible to the logged-in user. When search/client/stage/reason/cancelled-by/date filters change, the cards update with the table.

## Table layout fix

The table now uses a fixed `colgroup` and a minimum width of 1490px. It is never squeezed into the available content width. Instead, the history card exposes a horizontal scrollbar when necessary.

The Order column is sticky on the left and the Action column is sticky on the right. Text-heavy cells use safe truncation and two-line clamping to prevent columns from overlapping.

Client and user cells now use initials instead of potentially broken remote/storage images, matching the approved prototype and preventing broken image icons from damaging the row layout.

## Cancellation reason cleanup

Rich-text cancellation reasons can contain pasted images. The list now strips the editor `[Image]` placeholder before displaying/classifying the text. If no text remains, the table explains that the order should be opened to review cancellation attachments.

## Validation

- `php -l app/Services/CancelledOrderService.php` passed.
- `php -l app/Livewire/Orders/CancelledOrders.php` passed.
- `php -l tests/Feature/CancelledOrdersPrototypeImplementationTest.php` passed.
- Source and compiled theme CSS have matching brace counts.

The supplied archive does not contain `vendor/`, so the complete Laravel PHPUnit runtime suite could not be executed in this workspace.
