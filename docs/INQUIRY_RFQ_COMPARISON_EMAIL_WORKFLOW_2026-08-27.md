# Inquiry RFQ, comparison statement and supplier email workflow — 2026-08-27

## Scope

Implemented the Inquiry Details RFQ workflow from the supplied prototype while retaining FlowTrack's centralized theme and existing Inquiry permissions.

## Inquiry Details tabs

- Overview
- RFQ (shows invited supplier count)
- Comparison statement (shows submitted quotation count)
- Activity

The prior inline Activity section was moved into the Activity tab so the detail navigation matches the RFQ prototype without duplicating activity content.

## RFQ workflow

1. Search active suppliers by name, linked product category, contact or email.
2. Suppliers already invited to the Inquiry are excluded from the candidate list.
3. Clicking **Invite** creates one unique secure invitation and sends the RFQ invitation through the RFQ mail composer, which delegates delivery to the centralized `App\Services\Email\EmailService`.
4. All suppliers invited to one Inquiry share the same quotation due date.
5. The supplier opens the tokenized public RFQ URL without an account, can decline, or enter per-product unit prices, freight, lead time, validity and notes.
6. Submission creates/updates a normalized quotation and quote-item record and sends a supplier confirmation email through the centralized email service.
7. Comparison statement shows totals, freight, lead time, validity, submission date and a product-price matrix.
8. Awarding one quotation marks the winner, marks the other invitations not selected, links the winning supplier to the Inquiry products without removing existing supplier links, and sends award/not-selected emails.

## Email templates

- RFQ invitation — sent immediately when an internal user invites a supplier.
- Due-date reminder — scheduled daily at 09:00 for pending quotations due the next day.
- Quote received — sent when the supplier submits or updates a quotation.
- Supplier award — sent when an internal user awards the quotation.
- Not selected — sent to the other invited suppliers after an award.

All delivery goes through `App\Services\Inquiries\InquiryRfqEmailService`, which composes RFQ messages and delegates delivery to the existing provider-neutral central `EmailService`. No RFQ workflow code contains SMTP/provider-specific configuration, so changing the central provider does not require changes to the RFQ module.

## New persistence

Migration: `2026_08_26_235500_create_inquiry_rfq_tables.php`

Tables:
- `inquiry_rfq_invitations`
- `inquiry_rfq_quotes`
- `inquiry_rfq_quote_items`

## Deployment

Run:

```bash
php artisan migrate --force
php artisan optimize:clear
```

The scheduler must already be running (`php artisan schedule:run` from cron or the project's scheduler worker) for due-date reminders.
