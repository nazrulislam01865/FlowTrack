# Create Order Shipping Card Form UX — 2026-09-04

## Scope

The Create Order `Shipping setup` shipment editor was reorganized to follow the dedicated shipment form structure while preserving existing Livewire state, validation, master-data selectors, saved-address behavior, shipment modes, and shipping-method persistence.

## Structure

Each shipment is rendered through the existing reusable `jobs.create.shipping-row` component as a card:

1. Contact person + Phone
2. Full-width Shipping address
3. Country + State + City + Postal code
4. Shipment no. + Package / reference + Shipping method

Rows after Shipment 1 in `same_address` mode remain compact and reuse Shipment 1 contact/address data. Their shipment number, package/reference, and shipping method remain independently editable.

## UX changes

- Removed the dense six-column table header/layout from Create Order shipping.
- Added a clear Shipment N card header.
- Moved Remove shipment into a visible card-level action instead of a hidden overflow menu.
- Kept Use saved address directly beside the Shipping address heading.
- Matched field heights and spacing across text inputs, master-data selectors, and shipping-method picker.
- Added responsive 4-column → 2-column → 1-column location layout; no fixed-width shipment table is required.

## Architecture

No order/shipment domain behavior was moved into the view. `shipping-setup.blade.php` remains the orchestration component and `shipping-row.blade.php` remains the reusable per-shipment form component. The layout is isolated in `resources/css/modules/orders/create-shipping-setup.css`.
