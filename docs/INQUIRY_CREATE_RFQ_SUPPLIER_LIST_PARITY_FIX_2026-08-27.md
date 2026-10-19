# Create Inquiry RFQ supplier-list parity fix — 2026-08-27

## Problem
The Create Inquiry RFQ selector was not showing every supplier visible in the Suppliers page. Two implementation details caused the mismatch:

1. The create screen deliberately limited the initial RFQ supplier query to three rows.
2. The shared candidate service silently removed inactive suppliers and suppliers without a valid email address.

Because Supplier email is optional in Supplier creation, a legitimate Supplier-list record could therefore disappear entirely from the Create Inquiry RFQ section.

## Fix
- Create Inquiry now reads from `InquiryRfqService::supplierChoicesForWorkspace()`.
- The initial selector loads up to 100 supplier-list records and search can return up to 50 matches.
- Ordering matches the Supplier list (`sort_order`, then `name`).
- Supplier-list records remain visible even when they cannot yet receive RFQ mail.
- Inactive suppliers are rendered disabled with an `Inactive` badge.
- Active suppliers without a valid email are rendered disabled with an `Email required` badge and `No email configured` in the contact line.
- Active suppliers with a valid email remain selectable and retain their existing RFQ performance badge.
- Actual invitation validation remains strict: only active suppliers with a valid email can be submitted/sent.
- The existing Inquiry Details RFQ page continues to use the invitable-only candidate API, so its behaviour is preserved.

## Files
- `app/Services/Inquiries/InquiryRfqService.php`
- `app/Livewire/Inquiries/Concerns/BuildsInquiryPageData.php`
- `resources/views/components/inquiries/create-rfq.blade.php`
- `resources/views/components/inquiries/rfq-supplier-choice.blade.php`
- `resources/css/modules/application/21-inquiry-create-rfq.css`
- `tests/Feature/InquiryCreateRfqModuleTest.php`

No database migration is required for this fix.
