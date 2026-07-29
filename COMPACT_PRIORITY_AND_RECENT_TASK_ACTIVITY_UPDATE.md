# Compact Priority and Recent Task Activity Update

## Priority controls

- Job priority choices now render as a compact four-column selector on desktop.
- Tablet layouts use two columns and narrow mobile layouts use one column.
- Reduced control height, spacing, padding, checkbox size, and text size are applied consistently to the database-backed template and Vue interface.
- The same shared priority classes cover both create-job and edit-job forms.

## Dashboard recent activity

- The dashboard Recent Activity card now shows the newest visible task records.
- Entries include the task code, task title, related Job code, creator initials, and creation time.
- Selecting an entry opens the corresponding Task.
- Visibility is derived from the existing task authorization query, so users only receive activities for tasks they are allowed to view.
- The standalone template has a safe fallback that derives the feed from the currently loaded task collection.

## Validation

- All PHP source and test files pass `php -l` syntax validation.
- All JavaScript files and the standalone template's inline scripts pass `node --check`.
- A feature assertion was added for the `activities` payload returned by the template bootstrap endpoint.
