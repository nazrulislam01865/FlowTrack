# Order Details Add Product Supplier Fallback — 2026-08-22

## Requirement

In **Order Details → Products & quantities → Add product**, the Product Master supplier remains authoritative.

- If the selected Product already has an active Supplier linked in Product Master, FlowTrack fills that Supplier automatically and shows it as read-only.
- If the selected Product has no linked Supplier, the Supplier selector is enabled and the user may choose an active Supplier for this Order item.
- The manually selected Supplier is saved only on the Order item; it does not modify Product Master.

## Implementation

- `selectJobProduct()` now resolves the Product Master supplier and stores the supplier id/label plus a locked-state flag.
- `updateAddJobProductSupplierFromSelector()` handles the remote Supplier selector safely and re-checks Product Master before accepting a manual selection.
- `saveJobProduct()` re-checks Product Master before persistence. If a Supplier became linked after the user selected the Product, the Product Master Supplier wins.
- The Add Product UI renders a read-only linked-supplier control when a Product Master Supplier exists, otherwise it renders the searchable Supplier selector.

No workflow, stage, task, billing, payment, or Create Order supplier-skip logic was changed.
