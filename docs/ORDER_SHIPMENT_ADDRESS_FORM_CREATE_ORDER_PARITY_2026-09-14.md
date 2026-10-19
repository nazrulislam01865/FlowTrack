# Shipment Stage Address Form / Create Order Parity — 2026-09-14

## Goal

Keep **Add Shipment** and **Edit Shipment** in the Shipment workflow stage aligned with the approved **Create Order → Shipment address** UI and data contract, without changing the rest of the Shipment workflow.

## UI contract

The stage modal now exposes the same delivery-address fields as Create Order:

- Contact person — required
- Phone country code — required, shared searchable dropdown
- Phone — required
- Shipping address — required
- Use saved address
- Postal code — required

Country, State, City, Shipment No., Quantity, Package / Reference, and Shipping Method are not duplicated in this address modal. Existing values for hidden shipment fields are preserved when an existing shipment is edited. Shipping Method continues to be managed from the Shipment table / synchronized order shipping selection.

## Centralized styling

The modal uses the existing `ft-form-standard ft-form-standard--order` contract and also reuses the Create Order shipment layout classes (`ft-create-shipment-*`) for the field grid, phone row, searchable country-code control, saved-address action, address row, and postal field. Shipment-stage CSS only provides modal-specific containment/stacking and consumes the central form variables.

This keeps font family, typography, field height, border, radius, placeholder, focus state, and responsive behavior tied to the same centralized design system used by Create Order.

## Data flow

1. `ManagesOrderShipments` opens Add/Edit and prepares shipment address state.
2. Phone country codes come from active `phone_country_code` Master Data.
3. Saved addresses are loaded only while the shipment modal is open and are scoped to the order client.
4. `OrderShipmentPresenter` supplies the phone-code and saved-address lists to the modal.
5. `OrderShipmentService` validates the compact address contract and preserves non-address shipment data during edits.
6. Added/updated records continue to persist in `order_shipments`, so all shipment addresses remain visible in the Shipment stage.

## Compatibility and safety

- Existing shipments with no stored phone country code receive the project default active code when opened for address editing.
- Existing quantity/reference/method/urgency values are not cleared by the compact modal.
- Legacy explicit `same_address` callers remain supported, while normal Add Shipment creates an independently editable delivery address.
- Shipment confirmation still enforces the existing workflow rules, including shipping-method requirements.
- Saved-address selection is restricted to the current order's client.
