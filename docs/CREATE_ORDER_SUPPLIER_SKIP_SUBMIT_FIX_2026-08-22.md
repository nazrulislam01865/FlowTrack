# Create Order supplier-skip submit fix — 2026-08-22

## Problem

After introducing the temporary **Skip supplier** option in Create Order, the supplier-skip state was stored inside each `jobItems` row as a UI-only `supplier_skipped` field. That changed the payload submitted through the normal Create Order path and made the supplier bypass tightly coupled to the persisted line-item array.

## Fix

- Keep the normal `jobItems` payload unchanged.
- Store temporary supplier-skip decisions in a separate Livewire property: `createOrderSupplierSkipProductIds`.
- Keep `supplier_id` nullable only for products the user explicitly skipped.
- Re-resolve Product Master supplier data immediately before creation.
- If a supplier was linked after the user skipped it, Product Master wins and the temporary skip is removed.
- Removing a product also removes its temporary skip state.
- Pass skip state separately to the selected-product card only for display.
- Preserve normal `FlowJobItem` creation with `supplier_id = null` when the user explicitly skipped the supplier.

## Scope

This change affects only **Create Order → Products & quantities → temporary supplier skip**. Order Details supplier behavior and workflow/stage logic are unchanged.

## Files changed

- `app/Livewire/Jobs/Index.php`
- `resources/views/livewire/jobs/index.blade.php`
- `resources/views/components/jobs/create.blade.php`
- `resources/views/components/jobs/create-products.blade.php`
- `resources/views/components/catalog/create-product-quantity.blade.php`
- `resources/views/components/catalog/create-order-product-card.blade.php`
- `tests/Feature/CreateOrderProductSupplierUxTest.php`
