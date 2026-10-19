# Workflow Setup restored and separated

This package keeps both administration screens:

- **Workflow Setup** (`/workflow-setup`) — the original reusable workflow administration screen. Its existing templates, phases, Task Pack links, create/edit/delete behavior and routes are preserved.
- **Order Workflow Setup** (`/order-workflow-setup`) — the dedicated fixed seven-stage Order configuration introduced from the Order prototype.

## Separation rule

The dedicated Order Workflow Setup no longer loads the first arbitrary reusable Order workflow. It only reads/writes its reserved `ORDER_PROCESS` workflow record (or a collision-safe `ORDER_PROCESS-*` record created by the dedicated service).

This prevents generic/client-specific templates such as IID/NEP workflows from appearing inside Order Workflow Setup.

## Order creation

Create Order resolves only the dedicated Order Workflow Setup configuration. The workflow is displayed as fixed/read-only in the create form so users cannot accidentally switch an Order to a reusable generic Workflow Setup template.

## Order details

Order Details continues to render the Order's snapshotted workflow stages/tasks. New Orders therefore receive their stage/task structure from the dedicated Order Workflow Setup while historical Orders remain stable.
