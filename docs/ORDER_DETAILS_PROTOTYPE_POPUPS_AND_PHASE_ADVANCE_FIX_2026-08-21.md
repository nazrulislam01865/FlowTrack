# Order Details — Prototype Popup + Phase Progression Repair

Date: 2026-08-21

## Scope

This repair intentionally leaves Workflow Setup work aside and restores the approved Order Details runtime behavior from the interactive Order Process prototype.

## Root causes fixed

1. Task action dialogs had been collapsed into a generic confirmation modal. The prototype uses purpose-built interactions for artwork review/revision, client decision, production/QC issues, shipment, courier label, invoice and payment.
2. Runtime phase progression could read historical workflow phase rows through the legacy `workflow_id` mirror. Order Details could render the published phases while `completePhase()` saw a different phase collection.
3. Optional/configured document rows were being treated as phase blockers even where `document_required_before_completion` was false or the optional branch had never been activated.
4. Courier Label and Prepare Invoice were configured with document categories, causing the generic upload dialog/completion gate to replace their prototype-generated actions.
5. When a file-backed final task advanced the Order, Livewire kept the task panel focused on the phase that had just completed.

## Runtime behavior restored

- Purchase Order upload -> Send to Artwork Team -> Artwork stage.
- Artwork upload -> internal review -> revision loop or confirm -> send to Order Team -> Client ERP -> client decision -> optional sample branch -> Production.
- Production start -> issue/no-issue -> finish -> QC.
- QC check -> optional issue/resolution -> approve -> Shipment.
- Shipment information -> generate label -> preview/print label -> ship package -> Billing.
- Prepare invoice -> send invoice -> Payment.
- Partial payment remains open; full payment completes the final stage/order.

All normal task completion still uses `TaskService`, `OrderTaskSequenceService`, `JobService::maybeAutoAdvance()` and `JobService::completePhase()` so transition authority remains server-side.

## UI restored

Purpose-built popup variants now match the prototype interaction structure while using the current Order Details teal theme:

- Upload Purchase Order / Artwork / Revised Artwork / Sample Approval
- Review Artwork with latest version/file controls
- Request Artwork Revision
- Send Artwork email preview
- Client ERP confirmation
- Client Approved / Revision Requested decision
- Sample-required decision
- Production issue/report/resolve
- QC form and QC issue/report/resolve
- Shipment Information
- Courier Label Preview
- Dispatch shipment
- Prepare Bulk Invoice
- Send Invoice preview
- Record Payment

## Safety / architecture

- No frontend JavaScript decides stage progression.
- No N+1 query was added to the task list; evidence state is derived from the already-hydrated task/document/link collections.
- Historical/legacy phase rows are normalized away from active Order runtime operations.
- Optional document requirements cannot block a stage unless the task is actually applicable/activated and the setup explicitly requires the document before completion.
