# Job Details Phase Expansion Fix

Fixed the All phase tasks accordion in Job Details.

## What changed

- Replaced render-pass-only phase click handlers with a stable delegated accordion handler.
- Any phase header can now expand/collapse, including future phases whose tasks were pre-created at Job creation.
- Clicking anywhere on a phase header toggles the phase.
- The + Add Task button remains independent and does not toggle the phase.
- Expand all / Collapse all now use the same shared state helper.
- Caret and aria-expanded state stay synchronized.
- Expanded phase count is recalculated after toggles, filters, and re-renders.
- The accordion remains functional after task/status updates re-render Job Details.

No backend workflow logic or visual design was changed.
