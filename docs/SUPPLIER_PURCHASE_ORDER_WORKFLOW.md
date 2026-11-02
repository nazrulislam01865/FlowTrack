# Supplier purchase orders in the Artwork workflow

## Scope

The new **Create PO / Purchase Order** task follows **Internal Artwork Review / Artwork Confirmed** within Artwork in both Order workflow templates. It is separate from the existing **Upload Purchase Order** task in New Order (that document is supplied by the customer).

Both **Order Details** and **Orders > workflow quick actions** share the same Livewire component, dialog, server-side checks, PO records and email delivery logic.

## Operator process

1. Complete **Prepare & Upload Artwork** and **Internal Artwork Review**.
2. On **Create PO / Purchase Order**, choose **Single PO** (one PDF for all active Order products) or **Split PO**.
3. For a split, check one or more products to create a separate PO for each checked product. Other Order products are also included, grouped into PO(s) by supplier, so products cannot be silently omitted.
4. Click **Generate PO PDF(s)**. A unique PO number and downloadable PDF are created for every PO. PDFs appear in the Artwork task's document list. Generating PDFs completes the task, **without sending any email**.
5. Review each recipient field and email subject/body preview. The assigned supplier's primary email is used by default. Missing suppliers or supplier emails do not block PO generation; type recipients manually when needed. Multiple recipients are supported, separated by commas, semicolons or spaces, up to 20 per PO.
6. Click **Send PO** for one PO, or **Send all unsent POs** for several. Bulk sending validates every pending PO's addresses and PDF availability before attempting delivery; it skips already-sent POs. Email sends include the correct PDF attachment. Each PO tracks its own delivery state, last send time and recipients. **Resend PO** is available on sent POs.
7. Reopen a completed Artwork PO task with **View / Send PO** to review documents, send pending messages, or resend as necessary. PDFs remain accessible in the Order Documents archive and beneath the task.

### Supplier matching

- Prefer the supplier directly tagged on each Order product line (`flow_job_items.supplier_id`).
- If absent, use the catalog product's configured default supplier (`metadata.supplier_id` / `default_supplier_id`).
- A single PO with mixed supplier ownership has no automatically selected recipient; enter its intended recipient(s) manually. Split POs are grouped to preserve supplier-specific routing.
- Missing supplier assignments and missing email addresses are allowed at **generation**; a valid recipient address is required only to **send**.

## Data and safety

- `supplier_purchase_orders` stores order/task links, PO number and batch, supplier, immutable order-product snapshots, PDF document links, recipients and send status.
- PDFs are stored on Laravel's **local** disk and registered as `Document` rows of category **Supplier Purchase Order**; standard document permissions apply.
- PDF unit costs come from **supplier_unit_price**; customer **unit_price** is deliberately excluded.
- PDF generation is guarded against duplicate task PO batches; generation and workflow completion share a database transaction. Orphan PDF files are cleaned up on errors.
- Generated supplier PO documents cannot be deleted through the normal document-delete service.
- Sending is blocked for cancelled or held Orders and when the Order email module is disabled. Permissions are checked on the task before actions.
- Orders whose Artwork stage was already completed before installing this change are not forced back into Artwork. Their new historical PO task can be opened and generated manually without reopening the finished phase.

## Installation / deployment

Apply the changed files (preserving their paths), then from the Laravel project root:

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan queue:restart
```

The migration creates `supplier_purchase_orders` and inserts the new required task after `ART_INTERNAL_REVIEW` in **existing published and configured Order Artwork task packs**. It also rebuilds the legacy task-pack mirrors used by runtime tasks. New workflows use the updated fixed-stage definition automatically. After deployment, visit Order workflows to verify the task order, and open an active Order Details page to allow its tasks to synchronize.

## Suggested acceptance checklist

- Both configured Order workflow variants show **Create PO** directly after confirmed artwork.
- One product, multiple products, mixed supplier products, missing supplier, and missing supplier email all generate valid PDFs.
- Split selections generate independent POs, and all unselected active products remain accounted for.
- Every PO is visible under its Artwork task, in a new browser tab, and via download.
- Supplier default emails populate correctly. Two or more manually entered email recipients receive the correct PO attachment.
- Invalid emails / disabled Order email do not send any message, and a failed transport shows a retryable status.
- Generating a PO task once does not create duplicate records on double click.
- Reopen a finished PO task, send its unsent POs, and resend an already-sent PO.
- Existing customer-uploaded PO remains unchanged.

## Verification in this delivered archive

Changed PHP classes and migration passed `php -l`. The repository's standalone PDF engine passed a one-page A4 PDF smoke test (`pdfinfo`). Regression source/contract tests were added in `tests/Feature/SupplierPurchaseOrderWorkflowTest.php`.

**Not run:** Laravel application/Livewire/database tests because the uploaded project ZIP omits `vendor/` and the environment has no Composer dependencies for this project. Run `php artisan test --filter=SupplierPurchaseOrderWorkflowTest` and manual acceptance against a cloned database before deploying to production. Email delivery cannot be validated without configured provider credentials.
