# Document Upload and Task Setup Assignment Fix

This update fixes the two issues reported from the Task Details screen.

## 1. Required document upload from Task Details

The Task Details screen now keeps the selected file in browser memory after file selection, shows an immediate preview/status row, and sends the file to Laravel only when the user clicks **Save Task Update**.

For required document tasks, the save flow now uploads the file first, then saves the task update with the completed status. This prevents the previous validation error where the task tried to complete before the required document existed on the server.

## 2. Task Pack Setup default assignee

Task Pack item default assignees are now preserved and synced correctly:

- Seeded demo tasks now store `setup_assignee_id`.
- A repair migration recalculates setup assignees for existing generated tasks.
- Existing generated tasks without a manual assignee override are updated from Task Pack Setup when the pack is saved.
- Manual assignee overrides made from the Task details page are preserved.

## Verification performed

- PHP syntax check passed for all PHP files.
- JavaScript syntax check passed for `public/flowtrack-dynamic.js`.
- JavaScript syntax check passed for `public/flowtrack-searchable-dropdown.js`.

Runtime Laravel verification still requires `composer install` on your machine/server because `vendor/` is intentionally not included in the zip.
