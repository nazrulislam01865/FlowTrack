# Supplier Purchase Order — Final Artwork Task and Optional PDF Values

## Change

- `Create PO / Purchase Order` is the **last task in the Artwork phase** for both configurable Order workflow templates. It comes **after Client ERP / Approval and Sample Approval (when required)**, so an ordinary client revision happens before the PO is generated.
- The Sample Approval branch remains conditional; when no sample is needed, the PO is available after client approval.
- A new, idempotent data migration reorders the existing Artwork Task Pack records and their legacy `task_pack_tasks` sequence mirrors. It does **not** delete or recreate existing tasks, orders, historical POs, PDF documents or email history.
- The application continues to permit only **one active PO batch per final artwork revision**. The existing explicit artwork-revision flow still archives prior POs if an exceptional later revision happens.
- Email sending remains **optional**. Generated PDFs can be kept without sending.

## PDF field rules

- The official Excel template image and XLSX are preserved verbatim.
- Carton quantity is **not** inferred from the order's total product quantity; it has an optional explicit field instead.
- Unspecified carton type, weights, carton dimensions, bank information, payment method and payment terms are left blank (not filled with guessed defaults).
- The original template's zero-value formulas for unknown carton count, net weight and gross weight in the packing totals row are covered on generated PDFs only; the template source is unaltered.
- Known product lines, quantities, the order's currency, and explicitly provided supplier pricing are still printed.

## Deployment

1. Back up your current code and database. Keep your existing `.env` and stored files.
2. Replace project code with the updated ZIP, or merge only the changed files.
3. Run:

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan test --filter=SupplierPurchaseOrder
```

4. Verify in **both** Order workflow configurations that the PO appears after the Artwork client decision/sample step; generate a Single PO and Split POs, complete with and without email, and check an unknown-value PDF.

This update adds **no new table or destructive SQL**. The migration updates only the two ordering columns for the relevant Task Pack PO rows.

## Validation performed offline

- PHP syntax checks passed on all changed PHP files.
- A single-page A4 PDF sample rendered using the official template and only known inputs; unprovided carton and bank fields remained blank.
- Complete Laravel/database-backed workflow tests were not available without the project's Composer dependencies and configured runtime database.
