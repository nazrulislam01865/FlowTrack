# Order Workflow Setup

This implementation follows the supplied simplified seven-stage Order Workflow Setup prototype.

## Fixed Order stages
1. New Order
2. Artwork
3. Production
4. QC
5. Shipment
6. Billing
7. Payment

The stage names and order are fixed. Administrators can edit stage color and the tasks inside each stage.

## Architecture
- `OrderWorkflowSetupService` is the backend source for the order-only setup screen.
- The UI hides Task Pack internals; each stage owns one technical Task Pack so existing Order task generation remains reusable.
- Existing Orders are snapshotted before reusable workflow structure is changed.
- Task dependencies stay backend-controlled by `OrderTaskSequenceService`.
- Document requirements are configured per task and can block completion when enabled.
- Blade is split into reusable `components/order-workflow/*` components.
- CSS is scoped to `ft-order-workflow-*` to avoid page-level collisions.

## Route
`/order-workflow-setup` (`order-workflow.setup`)

The legacy Workflow Setup and Task Pack Setup routes remain available internally for compatibility, but the sidebar exposes only **Order Workflow Setup**.
