# Orders stage filter current-phase parity fix — 2026-09-05

## Problem
The Billing stage card count was calculated from each Order's live `workflow_phase_id`, but the Billing table filter also matched `source_workflow_phase_id`. Older/snapshotted/rebound Orders can keep source-phase provenance that differs from their live runtime phase. This allowed Orders still actively in Shipment to appear inside the Billing table.

A visible symptom was a Billing card count that did not match the table count, while rows inside Billing still showed Shipment actions such as **Review shipment details** or **Dispatch shipment**.

## Fix
- The seven-stage Orders table now filters only by `flow_jobs.workflow_phase_id`, matching the stage-card count source of truth.
- The cached stage-to-phase map now contains only actual runtime `workflow_phases.id` values. It no longer injects `source_workflow_phase_id` provenance ids.
- The phase-map cache key was bumped from `v2` to `v3`, so an old contaminated cache cannot survive deployment.
- Existing `source_workflow_phase_id` values are left untouched because they remain useful workflow provenance and snapshot metadata.

## Result
An Order cannot appear in Billing until its current runtime phase is actually Billing. Shipment Orders remain in Shipment until Shipment completion advances the workflow.
