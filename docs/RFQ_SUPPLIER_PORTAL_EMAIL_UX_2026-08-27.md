# RFQ Supplier Portal + Email UX — 2026-08-27

## Scope

Redesign the public supplier quotation experience opened from RFQ emails and refine the shared RFQ email presentation without changing RFQ business rules or central email delivery.

## Supplier quotation portal

- Loads the lightweight FlowTrack theme core before application CSS so centralized font, colors, radii and control tokens are available on the public page.
- Keeps the secure token route and existing submit/decline backend logic unchanged.
- Uses a compact branded header with secure-RFQ context.
- Adds a clear RFQ summary with supplier, due date, requested quantity and current state.
- Separates requested products from commercial terms.
- Adds clear currency prefixes and user-friendly placeholders.
- Adds a live estimated quotation total (product subtotal + freight) as presentation-only JavaScript; server calculations remain authoritative.
- Adds a clear submit action and guarded decline action.
- Improves success/error/closed states and mobile layout.
- Uses centralized FlowTrack theme variables rather than introducing a separate page theme.

## RFQ email presentation

- Keeps all sending through `InquiryRfqEmailService -> EmailService -> configured EmailTransport` (including e2a).
- Rebuilds the shared email frame as a robust table-based layout for email-client compatibility.
- Adds reusable `rfq-button` and `rfq-detail` Blade components.
- Applies the same compact hierarchy to invitation, reminder, quote-received, award and not-selected emails.
- Keeps secure links, subjects, recipients, tracking metadata and workflow triggers unchanged.

## Deployment

No database migration is required for this UX-only update.

The source RFQ CSS and the currently referenced compiled `app-DO3vKBO8.css` asset were both updated so the new portal styling is available without a frontend rebuild.
