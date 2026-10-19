# Inquiry RFQ action-column safe-area fix — 2026-08-29

## Problem

The supplier table's final **Actions** column was fixed at `110px`. Longer actions such as **Send invitation** could therefore run into the right edge of the inset supplier panel, making the section still look full-bleed even after the product-body spacing fix.

## Fix

- Increased the desktop Actions column to `156px`.
- Added central spacing-token padding on both sides of the final table column.
- Made RFQ action controls `border-box` so their padding is included in their measured width.
- In responsive card mode, removed the fixed column width and returned the action buttons to content-sized controls instead of stretching them to the card edge.
- Added a small mobile safe area for the action cell.
- Added a regression contract in `InquiryRfqProductActionSpacingTest.php`.

No RFQ sending, retry, supplier, quotation, or Livewire business logic was changed.
