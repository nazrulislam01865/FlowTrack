# Order Details Shipping Method / Urgency Full Sync - 2026-09-11

## Problem

The Planning & ownership card only represented `shipment_urgency_ids`. When the primary Shipment stage method was Sea Shipping, Air Shipping, or Road Freight, the Order correctly stored an empty urgency but Planning therefore displayed `Normal Service`. The Planning editor also listed only urgency records, so direct shipping methods could not be selected there.

## Fix

- Planning/Header now use one combined shipping-selection presentation model.
- The selector includes active direct Shipment Method master records (Sea, Air, Road, and other direct methods) plus Standard Express service levels.
- Composite UI values (`m:{methodId}:u:{urgencyId}`) keep method IDs and urgency IDs unambiguous while preserving the existing database schema.
- Direct methods persist `shipment_method_ids=[method]` and `shipment_urgency_ids=[]`.
- Express Normal persists the Express method with an empty urgency array; Urgent/Super Urgent persist the Express method plus the selected urgency ID.
- Planning -> Task 5.1 updates the primary `order_shipments` row immediately and tells the isolated Workflow Livewire component to rerender.
- Task 5.1 -> Planning dispatches the complete method/service selection (not only an urgency ID) and rerenders the parent Order shell.
- Existing label/tracking invalidation remains in place whenever the primary shipping selection changes.
- Existing urgency-only callers remain backward compatible through `syncPrimaryShipmentFromOrderUrgency()`.

## Database

No migration or schema change is required.

## Validation

All PHP and Blade files pass `php -l`. The supplied project archive does not contain `vendor/`, so PHPUnit cannot be executed in this sandbox.
