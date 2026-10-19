# Order Details - Multiple Linked Inquiries and Files (2026-09-03)

## Requirement

One Order may be related to multiple Inquiries. In Order Details > Inquiry, every linked Inquiry must be visible independently and all files uploaded to each Inquiry must appear under that Inquiry.

## Data model

- New `flow_job_inquiries` table stores the Order -> Inquiry links.
- One Order can have many rows in the table.
- `inquiry_id` is unique, preserving the existing rule that one Inquiry belongs to at most one Order.
- Existing `flow_jobs.source_inquiry_id` is retained as the legacy primary/oldest link so older reporting, list and conversion code remains compatible.
- The migration backfills current `source_inquiry_id` and `converted_job_id` relationships into the new table.
- When the primary Inquiry is unlinked/deleted, the oldest remaining linked Inquiry is promoted to `source_inquiry_id` automatically.

## Files

- Files are **not copied** into Order documents.
- The Inquiry tab reads authoritative `inquiry_documents` through `linkedInquiries.documents`.
- Every linked Inquiry has its own collapsible file list, preventing files from different Inquiries from being mixed visually.
- Only lightweight file metadata is eager loaded while the Inquiry tab is active.
- Uploader and Inquiry-task labels are eager loaded to avoid N+1 queries.
- Open/Download continue using the existing Inquiry document routes.

## Permissions

- Inquiry details require `inquiries.view`.
- Linking/unlinking additionally requires the Order link capability and edit access to the visible Order.
- File metadata requires `documents.view`.
- Download requires `documents.export`.
- Existing file routes remain the final authorization boundary.

## UX

- The tab badge shows the number of linked Inquiries.
- A summary shows linked Inquiry and file counts.
- Each Inquiry is rendered as a separate card with number, subject, client, status, owner, file count, Open and Unlink actions.
- File lists are open by default but can be collapsed independently.
- Search remains available after the first link, under **Link another inquiry**.
- Search results clearly distinguish available Inquiries, Inquiries already linked to this Order, and Inquiries linked elsewhere.
- Unlink acts only on the selected Inquiry relationship.
- Mobile layouts stack card actions and file actions safely.

## Deployment

This change includes a database migration. Deploy code and run:

```bash
php artisan migrate --force
php artisan optimize:clear
```
