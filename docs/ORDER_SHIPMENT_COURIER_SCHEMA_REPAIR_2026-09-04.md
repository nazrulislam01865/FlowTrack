# Order Shipment Courier Schema Repair — 2026-09-04

## Problem

Shipment tracking writes `order_shipments.courier_id`, but an installation can still have an older `order_shipments` table without that column when application files are deployed before pending database migrations are executed.

This caused MySQL error `SQLSTATE[42S22] Unknown column 'courier_id'` while saving courier/tracking details.

## Fix

- Fresh installs now create `order_shipments.courier_id` directly in the base shipment-table migration.
- Existing installs keep the original courier migration.
- A later idempotent repair migration also adds the column when it is still missing. This protects installations where the earlier migration was skipped or migration state became inconsistent.
- The column remains nullable and references `master_records`, preserving the Courier Master Data relationship.

## Deployment

Run database migrations after deploying the updated source:

```bash
php artisan migrate --force
```

For local development, `php artisan migrate` is sufficient.
