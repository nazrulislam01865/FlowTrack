# Order Shipment view/edit and location picker refinement — 2026-09-04

## Shipment Task 5.1 interaction

The shipment plan now opens in **view-only mode**. Users with permission can explicitly choose **Update details** / **Edit shipment details** before changing the multiple-shipment settings, addresses, package references, or shipping methods.

For an active Task 5.1, the existing fast path is preserved as **No changes — continue**. This confirms the current multi-shipment plan through `OrderShipmentService`, so the new aggregate validation remains the single source of truth before tracking is unlocked.

Each editable shipment has a clear Edit action for its delivery address/details. Additional shipments retain the remove action. Completed Task 5.1 data remains editable while the Shipment stage is still current without reopening the completed review task.

## Country and State master data

`LocationMasterDataService` centralizes location options using the cached Master Data service:

- Countries come from active `country` master rows (the Countries master-data table in the UI).
- States come from active `state` master rows whose `parent_id` is the selected Country.
- The default country comes from `metadata.is_default` rather than a hard-coded country name.
- Country changes clear the previous State selection.
- New/changed locations are validated server-side against the same master data. Exact legacy free-text values remain valid until the user changes them.
- Countries with no configured State rows do not require a State value.

Both Country and State use the shared searchable `x-ui.search-select` with fixed/teleported menus.

## Shipping method dropdown

The Shipment shipping-method picker now uses the shared floating-menu positioning helper and Alpine teleport. The menu is anchored to the trigger, opens above when needed, remains inside the viewport, and scrolls internally when necessary. The page/modal no longer needs to be scrolled just to reach shipping-method options.

## Files

- `app/Services/LocationMasterDataService.php`
- `app/Services/OrderShipmentService.php`
- `app/Livewire/Jobs/Concerns/BuildsOrderPageData.php`
- `app/Livewire/Jobs/Concerns/ManagesOrderShipments.php`
- `resources/views/components/jobs/order-detail/shipment/plan-table.blade.php`
- `resources/views/components/jobs/order-detail/shipment/add-modal.blade.php`
- `resources/views/components/jobs/order-detail/shipment/method-picker.blade.php`
- `resources/css/modules/orders/detail/shipment-edit-mode.css`
- `resources/css/modules/orders/detail/shipment-floating-overlays.css`
- `tests/Feature/OrderShipmentEditingUxImplementationTest.php`
