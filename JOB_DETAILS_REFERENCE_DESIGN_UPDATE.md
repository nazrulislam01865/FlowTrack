# Job Details Reference Design Update

Updated the Job Details page to follow the supplied reference screens.

## Included
- Redesigned Job Details header with breadcrumb, title, client/order, status badges, job lead, team avatars, and compact actions.
- Reordered tabs to: Overview, Tasks, Workflow, Commercial, Documents, Activity.
- Overview tab now matches the reference layout with alert strip, KPI cards, compact workflow strip, all phase tasks, next action card, job details, and latest update.
- Workflow tab now matches the reference layout with blocked alert, workflow summary cards, full workflow line, phase history table, and current phase gates.
- Documents tab now matches the reference layout with missing document warning, summary chips, document list, document preview, and required document status panel.
- Red-marked unnecessary controls from the reference were skipped: request document, upload document top action, status filter, list/grid switch, and request update action.
- Existing dynamic task/document/job data and backend actions remain connected.

## Verification
- PHP syntax check passed.
- JavaScript syntax check passed.
- FlowTrack Blade asset verification passed.
