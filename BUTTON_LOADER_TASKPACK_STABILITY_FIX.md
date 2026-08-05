# Button loader + Task Pack stability fix

## Button loader policy

The shared `interaction-feedback.js` no longer starts a spinner for every button click.

- Read-only GET interactions (tabs, filters, expand/collapse, document chips, phase toggles, view switches, etc.) do not receive button spinners.
- Page/sidebar navigation uses the global circular page loader only.
- File-picker/Choose from Documents utilities remain excluded.
- POST/PUT/PATCH/DELETE requests automatically attach the spinner to the initiating action button.
- Explicit create-form launchers such as New Job, New Client, New Workflow, Add Task Pack and Add Task retain short action feedback.
- Non-mutating form submissions do not get action spinners.

This keeps spinners on real Save/Create/Delete/Update/Comment operations while removing them from ordinary UI controls.

## Task Pack Add/Edit

Task Pack forms no longer use `#jobModal` as a temporary staging renderer when displayed as full pages.

- Add/Edit Task Pack renders directly into the standalone page.
- Any stale modal/overlay content is cleared before the full-page editor is mounted.
- Direct hard refresh waits for the real Task Pack form to be ready, not merely for a placeholder container.
- Editing fetches an authoritative Task Pack snapshot from `GET /flowtrack/template-api/task-packs/{taskPack}` before rendering.
- The endpoint returns no-cache headers, so the editor cannot be populated from a stale browser/API response.
- Add Task inside the Task Pack editor remains inline and scrolls the full page naturally.

No database migration is required.
