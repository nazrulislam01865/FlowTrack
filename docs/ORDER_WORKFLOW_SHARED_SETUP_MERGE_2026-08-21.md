# Order Workflow merged into shared Workflow Setup

## Final administration structure

- Workflow Setup: manages both Inquiry and Order workflow templates.
- Task Pack Setup: manages reusable task packs and their task definitions separately.
- The old `/order-workflow-setup` URL remains only as a backward-compatible redirect into Workflow Setup.

## Order workflow runtime contract

Order workflows keep the existing seven fixed stages and existing backend branching/action logic:

1. New Order
2. Artwork
3. Production
4. QC
5. Shipment
6. Billing
7. Payment

Stage names and sequence are protected because the runtime automation depends on them. Stage color and Task Pack mapping are managed from Workflow Setup.

## Task Packs

Order stage Task Packs are edited from Task Pack Setup, the same administration pattern used by Inquiry workflows. Core Order automation tasks keep their automation keys and relative order. Titles, assignees, departments, priority, timing, documents and extra tasks remain configurable.

## Multiple Order workflows

Multiple active Order workflows may coexist. New Orders select only active, client-available Order workflows that satisfy the seven-stage runtime contract. Existing active Orders remain bound to the Order workflow they were created from and are not moved to another workflow during synchronization.
