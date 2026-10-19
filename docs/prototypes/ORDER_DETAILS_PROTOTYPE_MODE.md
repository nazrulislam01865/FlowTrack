# Order Details — exact prototype mode

The Order Details workflow UI is intentionally locked to the supplied seven-stage interactive prototype for the current implementation pass.

Visible stages and sequence are fixed to:

1. New Order
2. Artwork
3. Production
4. QC
5. Shipment
6. Billing
7. Payment

The prototype task state machine is implemented in `resources/js/orders/detail.js` and the prototype workflow markup is in `resources/views/components/jobs/order-detail/workflow.blade.php`.

The legacy database workflow is deliberately not used to rename, merge, remove, or reorder these seven visible prototype stages in this mode. The selected Order only seeds display data such as Order number, client, owner, supplier, product, quantity, shipping urgency, and address.

This is intentional because the supplied HTML prototype is the visual and interaction contract for this pass.
