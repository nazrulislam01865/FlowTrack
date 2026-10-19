# Inquiry RFQ supplier settings — 2026-08-31

The Inquiry Details > RFQ settings control has been changed from a static automated-email preview gallery into operational supplier quotation settings.

## Settings

- Special note from buyer: optional message highlighted in the invitation email and visible in the supplier quotation form.
- Inquiry / product details for supplier: explicit supplier-safe context. Internal Inquiry notes are not exposed automatically.
- Quotation due date/time: the deadline displayed in the invitation and supplier portal.
- Secure link validity: configured in hours or days, starts each time an invitation is sent or resent, and is enforced server-side through `link_expires_at`.
- Submission confirmation: controls whether FlowTrack automatically emails the supplier after quotation submission.
- Due-date reminder: can be enabled/disabled with configurable lead time.
- Revision permission: controls whether a supplier may reopen a submitted quotation while the link is active and before award/rejection.
- Award notification: controls whether the awarded supplier is emailed automatically.
- Not-selected notification: controls whether the other invited suppliers are emailed automatically after an award.

## Persistence

Inquiry-level defaults are stored in `inquiry_rfq_settings`. Supplier invitation rows take a snapshot of supplier-facing content and access/automation settings when sent. Automation toggles are applied to open invitations immediately; delivered invitation link expiry only changes when that invitation is resent.

## Deployment

Run:

```bash
php artisan migrate --force
php artisan optimize:clear
```

The migration is `2026_08_31_193500_add_inquiry_rfq_supplier_settings.php`.
