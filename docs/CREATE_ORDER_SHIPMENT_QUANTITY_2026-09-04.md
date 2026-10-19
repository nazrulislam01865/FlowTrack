# Shipment Quantity — 2026-09-04

Added an optional per-shipment `quantity` field across Create Order and Shipment-stage add/edit workflows.

## Behavior

- Quantity is optional and independent for every shipment.
- When supplied, it must be a whole number of at least 1.
- Same-address shipment mode copies only delivery/contact fields; quantity is never copied between shipments.
- Shipment-stage plan and details views expose the stored quantity for review.

## Persistence

- `order_shipments.quantity` is a nullable unsigned integer added by an idempotent migration.
- Create Order DTO, shipment service, model fillable/casts, presenter, audit metadata, and Shipment-stage add/edit state are wired to the new field.
