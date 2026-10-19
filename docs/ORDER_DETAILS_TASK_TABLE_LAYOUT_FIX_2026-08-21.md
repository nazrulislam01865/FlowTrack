# Order Details Task Table Layout Fix - 2026-08-21

## Problem
The Inquiry-style inline assignee and due-date controls introduced a late CSS grid override that widened the Assignee, Due Date, and Action columns. On Order Details this pushed the Action column against/outside the task card and made the table look broken.

## Fix
- Restored the original aligned task-grid proportions.
- Added responsive task-grid safeguards so Task, Assignee, Due Date, Status / Files, and Action remain aligned.
- Kept the Action column fully visible with right-side spacing.
- Changed assignee, stage-assignee, and order-owner pickers to overlay the table/card instead of changing row height.
- Added a stable stacked layout below 900px.
- Kept the existing Inquiry-style inline-edit behavior and backend save methods unchanged.

## Scope
Presentation/layout only. No task sequencing, workflow, assignment, due-date, document, or order-owner backend logic was changed.
