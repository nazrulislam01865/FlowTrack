# Supplier PO optional email, completion, and artwork revision archives

## Scope

This change applies to the shared supplier PO task used by both order workflow templates, on the Order Details and Orders list Livewire screens. The existing Excel-derived PO PDF template is retained.

## Workflow

1. Generate Single or Split POs. Generation saves PDFs and documents but does **not** complete the task.
2. Optionally email each supplier (or send all) using the existing recipient fields. The PO PDF is attached automatically.
3. A successfully sent PO cannot be sent again. After all active POs are sent successfully, the task completes automatically, the dialog closes and the existing workflow sequence activates the next task.
4. Failed email deliveries remain retryable via **Resend PO**; successes cannot be resent.
5. **Complete without email** completes the task without requiring recipient addresses. When some POs were already sent, the action is labeled **Complete (skip remaining emails)**. The dialog closes and the next task becomes actionable according to the existing workflow gates.
6. Closing the dialog does not complete the task. The user can re-open the same pending POs via the task action.
7. A new artwork revision (internal or client) archives all active supplier POs for that task, retains PDF files and send history, and reopens the PO task with the rest of the artwork tasks. The next PO generation uses unique revision numbers (`SPO-000219-R2-01`, `...-R3-01`) and only shows current PDFs in the active PO dialog. Older PDFs are in **Archived Purchase Orders** beneath the task.

## Database (non-destructive)

Run `php artisan migrate --force` on the existing database. The new `2026_11_03_000003` migration adds a nullable `archived_at` timestamp plus a composite index on `(task_id, archived_at)`. It does not delete or overwrite existing supplier POs, orders, or supplier records. Do not use `migrate:fresh`, `db:wipe` or database refresh commands.

## Performance and safety

- Uses the existing indexed task/order lookups and eager supplier/job relations for PO previews; no extra per-document queries in the task table.
- Caches default supplier lookups during one generation, preventing repeated master-data queries for the same supplier.
- Uses an atomic status update to claim an unsent or failed email before calling the centralized email transport, preventing simultaneous double sends.
- Loads archived PO documents from the order's already-hydrated task documents; no archive query runs on each task row.
- The PO generation path keeps its previous transaction and file cleanup behavior. Archive writes occur only when an artwork revision is actually requested, inside the existing revision transaction.
- Generated and archived PO documents cannot be deleted through ordinary document deletion.

## Acceptance checklist

- [ ] Generate a single PO with no email; complete without email; modal closes; next workflow task opens.
- [ ] Generate 2+ split POs; send one; complete by skipping the remainder.
- [ ] Send all successfully; modal auto-closes; no resend action for successfully sent POs.
- [ ] Simulate email delivery failure; only failed PO shows Resend PO; retry succeeds and cannot resend a success.
- [ ] Trigger internal artwork revision after PO generation; archived PDF stays available, current active list empties, PO task becomes ready again.
- [ ] Repeat via client artwork revision; newly generated PO has an R2/R3 suffix and is not mixed with archived batches.
- [ ] Validate original Excel-derived PDF layout, supplier addresses, access control, and both workflow templates.
- [ ] Check SQL log/query counts on 1, 2 and 10 PO cases; test full Laravel integration and email transport in staging.

## Installation

Copy updated files over the existing project while retaining your `.env`, `storage` data, and database. Then run:

```bash
php artisan migrate --force
npm ci
npm run build
php artisan optimize:clear
php artisan test --filter=SupplierPurchaseOrder
```

This archive does not include Composer dependencies. In a clean installation run `composer install` using the existing project's standard procedure.
