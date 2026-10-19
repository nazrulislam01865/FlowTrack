# Create Order shipping-method prototype — 2026-09-04

## Scope

The Create Order **Schedule & owner** section now uses the approved Shipping method prototype instead of the inline shipment-urgency radio row.

The visual contract is:

- `Sea Shipping` — `About 1 month`
- `Air Shipping` — `About 10–15 days`
- `STANDARD EXPRESS SHIPPING`
  - `Normal` — `About 7 days`
  - `Urgent` — `About 3 days`
  - `Super Urgent` — `About 1–2 days`
- selected values are rendered as compact shipping cards with the same icon/title/estimate structure as the prototype;
- multiple shipment methods can be selected for one Order, while Standard Express still has only one urgency level at a time.

## Persistence model

The existing Master Data boundaries are preserved instead of flattening method and urgency into one field:

- `shipment_method` Master Data backs Sea/Air/Express choices;
- `shipment_urgency` Master Data backs Standard Express urgency;
- `flow_jobs.shipment_method_ids` stores the selected shipment methods;
- `flow_jobs.shipment_urgency_ids` retains the existing single urgency value.

`Normal` remains the established empty urgency value. This keeps existing urgent/non-urgent report semantics unchanged.

## Architecture

- `App\Support\CreateOrderShippingMethodPresenter` owns canonical prototype labels, grouping and delivery-estimate copy.
- `x-jobs.create.shipping-method-picker` owns the Create Order picker composition.
- `x-jobs.create.shipping-method-icon` owns the reusable inline SVG icons.
- Livewire validates selected method IDs against active `shipment_method` Master Data and urgency IDs against active `shipment_urgency` Master Data.
- Selecting the same visible option again removes it, so the prototype does not need a separate remove control.
- Create Order CSS is scoped to `.ft-create-job-page` and consumes centralized `--ft-*` tokens/theme variables.

## Database

Migration:

`2026_09_04_131500_add_shipment_method_ids_to_flow_jobs.php`

This adds one nullable JSON column and does not modify existing Master Data records.
