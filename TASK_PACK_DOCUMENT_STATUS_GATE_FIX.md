# Task Pack Document Visibility & Task Status Gate

## Changes

- Job Details now treats backend Task Pack document requirements as authoritative and shows every configured required document under its workflow phase, including future phases.
- Requirement payloads now carry the linked Flow Task ID/code/title (when a task exists) and an authoritative `present` flag.
- Uploads and "Choose from Documents" pass the exact Task ID when the requirement belongs to a Task Pack task, keeping evidence linked to the correct task.
- A Task Pack item with a configured Required Document is now blocked from **any Task status transition** until that exact document has been uploaded or linked.
- The status gate is enforced in the shared backend `TaskTransitionAction`, so it applies to Task Details, Job Details, My Work, Board and direct API updates.
- Task Details exposes `documentRequirementSatisfied`, `statusChangeBlockedByDocument`, and a clear block message. The status editor is locked while evidence is missing.
- Job Details inline status selectors are disabled while their Task Pack document requirement is missing and show the required document beside the task.
- Task Pack Setup helper text now states that the required file is needed before status can change.

## Data model

No migration is required. Existing `task_pack_items.document_category_id`, `flow_tasks.document_category_id`, and explicit document linkage fields are used.

## Validation

- PHP syntax checked across application/routes/database/tests.
- Source and generated FlowTrack JavaScript syntax checked.
- FlowTrack asset/UI verifier passed.
- A feature regression test was added for visibility + blocking every status transition until evidence exists. Full Laravel test execution requires the project's `vendor/` directory, which is not included in this archive.
