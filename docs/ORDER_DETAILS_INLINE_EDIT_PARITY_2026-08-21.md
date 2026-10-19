# Order Details inline-edit parity — 2026-08-21

This release aligns Order Details inline editing with the Inquiry Details interaction pattern.

## Updated fields

- Every rendered Order task assignee uses the shared searchable `x-ui.inline-remote-user` picker.
- Task assignee display now uses the shared live avatar, name, pencil affordance, immediate save state, outside-click cancel, and Escape cancel behavior.
- Task due date is inline editable with the same date-picker behavior as Inquiry tasks and saves through `Jobs\Index::updateTaskDueDateFromJob()`.
- Locked/upcoming tasks display their saved due date or `Set due date` instead of hiding the field behind a dash.
- Stage-card assignee editing uses the same shared picker and pencil interaction.
- Planning & ownership → Order owner uses the same shared picker and inline live-avatar pattern.

## Reuse / backend

No new assignee or date persistence endpoint was added. The implementation reuses:

- `window.FlowTrackInlineEdit`
- `x-ui.inline-remote-user`
- `x-ui.inline-live-avatar`
- `x-ui.inline-save-state`
- `Jobs\Index::updateTaskAssigneeFromJob()`
- `Jobs\Index::updateTaskDueDateFromJob()`
- `Jobs\Index::updateJobOwner()`

The change does not add task queries and does not alter workflow sequencing.
