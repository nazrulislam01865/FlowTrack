# Order Shipment row-edit UX — 2026-09-04

## Scope

Shipment-stage Order Details was simplified so the default state is read-only and every physical shipment owns its own edit action.

## Behaviour

- Task 5.1 no longer has a global shipment edit mode.
- Each shipment row has its own **Edit** action for address, shipping method and package/reference changes.
- **Add shipment** is available directly; adding a second shipment automatically enables multi-shipment state.
- The shipment plan (`single`, `same address`, `multiple addresses`) is derived from the actual shipment rows and is normalized after add, edit and remove operations.
- The existing **No changes — continue** action remains available for Task 5.1.
- Add/Edit Shipment supports **Same as Shipment 1** and **Different address** for non-primary shipments.
- Country uses active Country master data and State uses State master data filtered by the selected Country.
- Shipping methods are edited inside the shipment modal with the existing teleported/fixed picker, so the page does not need to be scrolled to reach options.
- Task 5.2 is read-only by default per row. **Add tracking** / **Edit tracking** opens only that row's editor.
- The courier **Print label** action was removed from the Shipment-stage UI.

## Layout

The Task 5.1 table groups recipient/address/location into one Delivery details column to prevent long shipping addresses from pushing Shipping Method or Actions outside the task card. Responsive overflow is retained only for narrower viewports.

## Validation

- PHP syntax validation passed for all application/config/database/route/test PHP files in the package.
- All CSS sources parse successfully.
- Source-contract checks confirm shipment-wise editing, retained continue action, master-data location selectors and removal of the print-label action.
