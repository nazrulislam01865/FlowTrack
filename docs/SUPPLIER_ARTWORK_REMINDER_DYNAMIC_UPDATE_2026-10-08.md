# FlowTracker supplier reminder dynamic update (2026-10-08)

Scope: Only the artwork-confirmation supplier reminder module, including the existing five-screen STEP PROMO design. No other workflow events have been enabled.

- Create Reminder creates a saved draft. Administrators can configure several drafts, but only one published reminder is active for ART_INTERNAL_REVIEW -> confirm at a time. Publishing a different reminder deactivates the previously active one; Pause stops automated sending independently of the global Order Email Service.
- The five screens now load workspace-scoped saved templates and delivery records, with functional search/status/stage filters, editable reminder names, immediate or delayed queue timing, content visibility, live previews, test email sending, and publication statuses. Mandatory safety checks and the exact event/recipient remain fixed.
- All actual outgoing emails use the existing centralized EmailService and module email controls. Recipients are resolved from flow_job_items.supplier_id -> master_records.metadata.email, not from every supplier tagged to the product.
- A missing/invalid supplier email is skipped, the reason is written to the delivery history, and artwork confirmation continues. A nonblocking status message is shown in the Order Details and Orders-list completion UI. There is never a fallback to another supplier's address.
- Repeat confirmation and concurrent job retries are protected by the existing per-task/per-supplier delivery records. The job is queued after the order transaction commits. No new polling or recurring query is added to the UI.
- Artwork links retain the existing access/verification boundary; no raw private storage URL is exposed.

Deployment: Run the new migration `2026_10_08_000002_allow_multiple_artwork_reminder_templates.php` through the project's documented deploy procedure (normally `php artisan migrate --force`). The migration preserves the existing published template, if any, as the active template. Ensure the existing email queue worker remains running. The archive intentionally does not contain local `.env` credentials.

Validation here: PHP syntax checks, JavaScript syntax check, five-screen DOM structural checks, route/supplier guard checks, default and token interpolation checks. Full Laravel integration tests and live SMTP delivery could not be run without the server's Composer dependencies and database/provider credentials.
