# Order Shipment modal shipping method — 2026-09-14

- Added the existing Shipment shipping-method picker to both Add Shipment and Edit Shipment modals.
- Kept the existing modal shell, sizing, header, footer, scrolling, and address-field layout unchanged.
- The picker uses the existing Shipment master-data options and the existing `selectShipmentModalMethod` Livewire action.
- Shipping method is required when saving from these Shipment-stage forms.
- Existing row-level shipping-method editing remains available and unchanged.
- Primary-shipment method changes continue to synchronize the Order-level shipping selection immediately, without requiring a page refresh.
- If a saved tracking number/label belongs to an older shipping method, changing the method through the modal clears that stale carrier data and reopens the tracking task, matching the existing row-level method editor behavior.
