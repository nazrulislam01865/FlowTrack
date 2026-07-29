# Job Document Upload and Delete UX Update

## Job-specific document uploads

- Jobs now expose an upload action from the Workflow tab when the current phase requires a document.
- The Documents tab exposes `Upload for this Job` for general job documents.
- The upload is submitted to the database-backed Template API and is permanently linked to the selected Job.
- The current phase's required document category is preselected for gate uploads.
- Successful uploads recalculate phase readiness, Job health and optional automatic workflow movement.
- If the upload activates the next phase, the returned Job and generated Tasks are synchronized immediately.

## Safer Job deletion

- The large destructive header button was replaced by a compact `Job Actions` control.
- Deletion now uses a dedicated confirmation dialog showing the number of related Tasks and documents.
- The permanent delete button remains disabled until the user explicitly acknowledges the impact.

## API

- Added `POST /flowtrack/template-api/documents`.
- The endpoint accepts the same validation rules as the primary Document Archive upload flow.
- The response includes the uploaded document, refreshed Job state and refreshed Job Tasks.
