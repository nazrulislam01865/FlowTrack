# Job Details document type + transition UI fix

## Changes

- Removed the trailing three-dot transition menu beside **Move to [next phase]** in Job Details → Workflow.
- Reintroduced a **Document type** dropdown in Job Details → Documents → Add documents.
- The dropdown only lists document categories actually configured for the Job's Workflow/Task Pack requirements.
- Selecting a document type resolves the matching requirement automatically. The resolver prefers:
  1. an unmet requirement in the currently selected/active phase,
  2. the next unmet requirement for that document type,
  3. the matching configured requirement if all are already satisfied.
- When the requirement belongs to a Task Pack item, the existing backend upload action persists the exact `workflow_phase_id`, `task_pack_item_id`, and matching `flow_task_id`, so the document is linked to the correct Task automatically.
- Clicking an individual requirement's **Upload** button also synchronizes the Document type dropdown to that requirement before opening the file picker.
- **Browse**, drag/drop, and **Choose from Documents** require a document type before proceeding, preventing accidental linkage to the wrong requirement.

## Updated source files

- `resources/js/flowtrack/modules/job-details.js`
- `resources/js/flowtrack/modules/job-shared.js`
- `resources/css/flowtrack/runtime/06-details.css`

Generated runtime/public assets and the CSS manifest were refreshed as well.
