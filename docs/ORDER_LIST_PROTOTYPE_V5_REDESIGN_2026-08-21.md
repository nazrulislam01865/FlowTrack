# Order List Prototype v5 Redesign — 2026-08-21

## Scope

The `/orders` list view was rebuilt to follow `FlowTrack_Order_List_InPlace_Stage_Filter_Prototype_v5.html` while keeping Laravel/Livewire/MySQL as the source of truth.

## UI implemented

- Orders page heading, subtitle and Bulk order / Create order actions.
- Seven workflow-stage cards: New Order, Artwork, Production, QC, Shipment, Billing and Payment.
- Clicking a stage filters the existing Orders page in place.
- Search, Client, Owner, Stage, From, To and Clear controls.
- Stage-specific quick filters and stage-specific secondary selectors.
- Stage-specific table columns/actions matching the prototype.
- Compact pagination with previous/next arrows and page numbers.
- Orders top-bar context with Export Summary.

## Backend binding

- Stage cards are loaded from the dedicated Order Workflow Setup rather than hard-coded list data.
- Stage counts are aggregated from real visible Orders.
- Rows are paginated from the existing Order visibility query.
- Current task actions are derived from `OrderWorkflowActionService` and task `automation_key` values.
- PO/artwork/label documents, shipment metadata, QC inspection data, invoice and payment information are read from existing backend relationships/activity.
- Task, document, item, supplier, invoice/payment and workflow relationships are eager-loaded before row presentation to avoid per-row N+1 reads.

## Isolation

The redesign is scoped to `.ft-order-list-v5`; Order Details, Create Order and Inquiry styles are not replaced by this list-page CSS.

## Build

The compiled Orders CSS and `public/build/manifest.json` are included in the release, so a Vite rebuild is not required for this package.
