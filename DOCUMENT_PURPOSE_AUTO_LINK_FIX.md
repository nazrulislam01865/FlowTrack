# Job Details Document Auto-Link Fix

## Changed
- Removed the visible `Document purpose` field and `Select phase, task or requirement` dropdown from Job Details > Documents.
- Job document uploads now resolve their destination from the Job's configured Workflow Phase / Task Pack document requirements.
- The current phase is the initial automatic upload target when it has document requirements.
- Expanding/selecting another phase makes that phase the automatic upload target.
- Within a phase, FlowTrack chooses the first unmet required document in Task Pack/workflow order, then advances to the next unmet requirement after refresh.
- Clicking an `Upload` button beside a missing required document always targets that exact requirement.
- `Choose from Documents` uses the same automatic target.
- If the selected phase has no configured requirement, upload is blocked with a clear message rather than attaching the file to the wrong phase.

## Preserved
- Existing document permissions and 20 MB validation.
- Exact workflow phase, Task Pack item, Flow Task and document category linkage performed by the existing backend upload action.
- Required-document readiness and workflow/task completion logic.
