# Shared missing-product-supplier resolution (2026-09-04)

## Scope

The Product picker now uses one missing-Supplier workflow in all four product-entry surfaces:

- Create Order
- Order Details > Add product
- Create Inquiry > Products & quantities
- Inquiry Details > Add product

When the selected Product has no Product Master Supplier, users can link an existing Supplier, create a new Supplier, or explicitly continue without one where the workflow permits it. Order Details now mirrors Create Order: an explicit skip keeps the Order item supplier null so it can be assigned later.

## Reusable architecture

- `app/Services/Catalog/ProductSupplierResolutionService.php` owns concurrency-safe Product Master persistence.
- `app/Livewire/Concerns/ManagesMissingProductSupplier.php` owns shared modal state, validation and orchestration.
- `app/Livewire/Jobs/Concerns/HandlesMissingProductSupplierContext.php` applies the shared workflow to Order screens.
- `app/Livewire/Inquiries/Concerns/HandlesMissingProductSupplierContext.php` applies the shared workflow to Inquiry screens.
- `resources/views/components/catalog/missing-product-supplier-modal.blade.php` is the single reusable modal UI.
- `resources/views/components/catalog/detail-add-product.blade.php` exposes a reusable `missingSupplierMethod` hook for detail screens.

The legacy Create Order Supplier actions remain as thin compatibility wrappers around the shared service so old call sites/snapshots do not create a second persistence implementation.

## Data and safety rules

- Supplier resolution is workspace-scoped and accepts active Product/Supplier master records only.
- Product rows are locked before Product Master supplier mutation so concurrent resolutions do not overwrite the first canonical resolution.
- The pending product/row is revalidated before mutation to prevent a stale Livewire modal from changing the wrong Product Master record.
- Supplier creation rejects duplicate names in the workspace and stores the optional email in Supplier metadata, matching the existing Create Order behavior.
- A Supplier created/linked from Create Inquiry is immediately seeded into that Product's RFQ supplier state.
- No database migration is required.

## Existing behavior intentionally preserved

- Order Details retains its existing Order-only supplier override/change selector and supports an explicit temporary supplier skip before adding the item.
- Create Order retains its explicit "continue without supplier" path.
- Inquiry product creation remains product-scoped; Supplier resolution updates Product Master rather than adding a new Inquiry-item supplier column.
- Quantity and unit-price behavior remains unchanged.
