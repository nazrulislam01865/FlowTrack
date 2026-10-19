# Create Order shipment address centralization and multiple-address behavior — 2026-09-14

## Scope

This change is limited to the Create Order **Shipment address** section and its existing shipment persistence path.

## UI consistency

- Shipment contact, phone, shipping address, and postal-code fields now use the existing `ft-create-field` / `ft-form-standard--order` contract.
- Field font family, label typography, control typography, control height, borders, radius, placeholder color, and focus treatment come from the centralized FlowTrack form theme.
- Shipment-specific CSS now owns layout only where possible.
- The duplicate visible `Shipping address` label was removed. The input keeps an accessibility label through `aria-label`.

## Address modes

### Address

- Keeps a single Create Order shipment address.
- The **Add shipment** action is not rendered.
- Switching back to this option normalizes the draft to Shipment 1 so hidden stale rows cannot be persisted accidentally.

### Multiple address

- Uses `OrderShipmentService::MODE_MULTIPLE_ADDRESS`.
- Shows the **Add shipment** action.
- Every new shipment starts with an independent blank delivery address instead of silently copying Shipment 1.
- Each row can also use a different saved client shipping address.

## Persistence and Shipment stage

No parallel persistence model was added.

1. Livewire keeps the Create Order rows in `createShipments`.
2. `OrderCreateData` converts every draft row to `initial_shipments`.
3. `LegacyJobService` calls `OrderShipmentService::createInitialShipments()` in the existing create transaction.
4. `OrderShipmentService` stores every row in `order_shipments` with its own sequence and derives the aggregate address mode from the saved rows.
5. `OrderShipmentPresenter` exposes all stored shipment rows to the Shipment stage.
6. The Shipment-stage plan table renders every stored row, so multiple Create Order addresses are immediately available there.

## Compatibility

The legacy `same_address` mode is still accepted for already-open browser snapshots and non-current callers, but it is no longer a visible Create Order choice. Existing Order Shipment stage editing remains unchanged.
