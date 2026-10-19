# Create Inquiry RFQ selector — 2026-08-27

## Scope

The Create Inquiry page now contains the approved optional RFQ selector between Product & Quantities and Attachments.

## UI architecture

- `resources/views/components/inquiries/create-rfq.blade.php` owns the two-card RFQ layout.
- `resources/views/components/inquiries/rfq-supplier-choice.blade.php` owns reusable selectable supplier rows.
- `resources/css/modules/application/21-inquiry-create-rfq.css` owns the RFQ-create presentation and responsive behavior.
- The main Create Inquiry Blade only mounts the component; RFQ markup is not embedded into the page monolith.

## Behavior

- Search matches supplier name, linked category, contact, or email.
- The initial view is bounded to three suppliers; a search can show up to twenty matches.
- Selected suppliers remain RFQ participants only and are never assigned to the Product by selection.
- Quotation due date defaults to seven days from the workspace-local current date.
- The default message is `Please quote your best unit price, lead time, shipping and sample options.`
- The message is included in the actual RFQ invitation email.
- On Create Inquiry, invitations are sent only after the Inquiry, items, taskflow, and uploaded documents have been created.
- Save Draft does not send external RFQ email.
- Later suppliers invited from the Inquiry RFQ page inherit the first invitation's due date and request message.

## Data

Migration `2026_08_27_070000_add_request_message_to_inquiry_rfq_invitations.php` adds nullable `request_message` to `inquiry_rfq_invitations`.

## Reuse

`InquiryRfqService::candidateSuppliersForWorkspace()` is the shared supplier source used before an Inquiry exists, while the existing detail RFQ candidate method delegates to it. Candidate badges use bulk historical response/lead-time data when available.
