# Order workflow email handoff — 2026-08-27

## Implemented

The Order workflow now performs real email delivery for these automation tasks:

- `NEW_SEND_PO_ARTWORK` — **Send Purchase Order to Artwork Team**
- `ART_SEND_ORDER_TEAM` — **Send Artwork to Order Team**

Both actions use the existing provider-neutral `App\Services\Email\EmailService`. The Order module does not contain e2a/SMTP/provider-specific code.

## Purchase Order handoff

1. `Upload Purchase Order` stores the Purchase Order using the existing secure document storage.
2. `Send Purchase Order to Artwork Team` opens a confirmation preview instead of silently completing.
3. The latest Purchase Order file from `NEW_UPLOAD_PO` is attached to the email.
4. Recipients are resolved from the destination Artwork workflow task/department.
5. The task completes only after the configured email provider accepts the message.
6. A `job.purchase_order_emailed_to_artwork_team` activity is recorded with the document ID, recipient count and email tracking ID.

## Artwork handoff

1. The latest artwork version from `ART_PREPARE_UPLOAD` is used.
2. `Send Artwork to Order Team` shows the actual recipient list, subject and attachment in the existing artwork handoff modal.
3. The latest confirmed artwork is attached to the email.
4. Recipients are resolved from the destination Order Team workflow task/department.
5. The workflow task completes only after email acceptance.
6. A `job.artwork_emailed_to_order_team` activity is recorded.

## Recipient resolution

The destination task assignee is always included when active and email-enabled. Active users in the assignee's department are also included. If the configured Order Workflow master department maps to the legacy user department by code/name, all active email-enabled members of that department are included too.

If no recipient can be resolved, the email is not sent and the workflow task is not completed. The UI shows a validation message explaining that the destination task/department needs an active user with an email address.

## Company identity

Internal Order handoff emails use Company Setup for the visible company identity and footer details, with existing Branding Setup assets as fallback. The email includes company display/legal name, address and configured contact details when available.

## Attachments and security

Attachments are resolved through `SecureDocumentStorage`, so private/shared/object storage compatibility is retained. The existing central email transport size/security limits still apply.

## Deployment

No database migration is required. After deployment run:

```bash
php artisan optimize:clear
php artisan queue:restart
```

If queued email is enabled for other mail types, keep the email queue worker running as usual. These two workflow handoffs intentionally use synchronous provider acceptance so the task cannot be marked complete before the send is accepted.
