# Create Order supplier options component wiring fix - 2026-08-29

## Problem

Opening **Create new product** caused a Livewire 500 response:

`Undefined variable $newProductSupplierOptions` in `resources/views/components/jobs/create/product-modal.blade.php`.

The supplier options were built correctly in `BuildsOrderPageData`, but `x-jobs.create` is a Blade component boundary with an explicit `@props` contract. The new variable was never passed into that component, so nested includes could not access it.

## Fix

- Added `newProductSupplierOptions` to `resources/views/components/jobs/create.blade.php` with a safe `collect()` default.
- Passed `:new-product-supplier-options="$newProductSupplierOptions"` from `resources/views/livewire/jobs/index.blade.php`.
- Kept the supplier picker backed by the canonical active Supplier list created by `RemoteSelectOptionsService`.
- Added source regression assertions for both sides of the Blade component boundary.

This preserves the optional supplier behavior while allowing the dropdown to open and display Supplier Master Data entries.
