# Supplier Artwork Confirmation Reminder — FlowTracker

The five screens under **Administration → Reminder Setup** use the provided STEP PROMO UI concept without a second sidebar/theme. Only the existing `ART_INTERNAL_REVIEW` → `confirm` workflow action is supported by this release.

## What is implemented

- Draft configuration and published configuration are stored separately per workspace. No email sends before the first publication.
- Artwork confirmation schedules a queue job only after the order transaction commits and only when a published configuration exists.
- Suppliers are resolved from `flow_job_items.supplier_id` (not from the product–supplier tag list). Several lines assigned to the same supplier are grouped into a single notification. For legacy orders with **no** supplier-assigned lines, `flow_jobs.supplier_id` is used, scoped to the current workspace.
- Missing supplier/email, centrally disabled Order email service, and failed provider delivery are recorded as skipped/failed in Delivery History. A database uniqueness constraint prevents repeated sends for each confirmation task and supplier.
- Delivery uses the existing central `EmailService` and email provider. Test email uses `sendNow`; workflow email uses the configured `emails` queue, with no provider-specific API calls.
- Subject and body variables are allowlisted; markup is escaped. Test emails are marked `TEST` and use sample data. Internal task details cannot be exposed to a supplier.
- The CTA targets the existing public order tracking **lookup page**, requiring the recipient to verify order details and an authorized email; private document paths and whole-order artwork PDFs are not distributed to possibly unrelated suppliers.
- Only workspace administrators can access the settings, routes and history.

## Deploy

1. Deploy the full updated application using your existing documented workflow.
2. Run `php artisan migrate --force` once to add the two small indexed reminder tables.
3. Clear configuration/routes/views according to your existing deployment document.
4. Ensure your **existing** FlowTracker email queue worker is running and processing the configured queue in `flowtrack_email.queue.name` (usually `emails`). No second queue service is required.
5. Go to **Administration → Reminder Setup**, review the content, send a test, then **Save & Publish** to activate new confirmation events. Previously confirmed artwork does not automatically send reminders.
6. Keep the existing Order email service enabled in Administration settings. It remains the final delivery guard.

## Known limits

- Send timing is immediate after confirmation; delayed sends are intentionally disabled until specified.
- No separate supplier web portal or unsafe direct artwork download URL is invented.
- Actual provider acceptance is recorded as `sent`; mail inbox arrival cannot be guaranteed by SMTP/provider acceptance alone.
- PHPUnit/HTTP integration tests must be run after installing the existing Composer dependencies and configuring a test database. Upload ZIP did not contain `vendor`.
