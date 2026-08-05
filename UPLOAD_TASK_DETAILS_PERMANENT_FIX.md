# Upload / Task Details permanent fix

## Task Details consistency
- Job Details task links now ensure the final `task-details` runtime module before opening a task.
- Programmatic task openings from Job Details use the same helper.
- The authoritative Task Details endpoint is still fetched with `cache: no-store`.
- All entry points therefore render the same final Task Details UI.

## Generic Task attachments
- Normal Tasks can now hold ordinary attachments without requiring a Task Pack document category.
- Generic attachments use the existing Job attachment storage path but persist `flow_task_id` and the Task phase.
- Required-document Tasks continue to use `/template-api/documents` and the configured Task Pack requirement.
- Ordinary attachments do not satisfy workflow/document readiness requirements.

## Choose from Documents
- Stored files can be copied and attached to a normal Task through the generic attachment endpoint.
- The copied document persists `flow_task_id`.
- Same-Job documents can be selected for a Task unless already attached to that Task.
- Required-document Tasks still use strict document requirement linking.

## Immediate UI refresh
- After upload/link, Task Details is re-fetched from the authoritative endpoint so the new attachment is shown immediately.
- File inputs are cleared after each attempt so selecting the same filename again works.
