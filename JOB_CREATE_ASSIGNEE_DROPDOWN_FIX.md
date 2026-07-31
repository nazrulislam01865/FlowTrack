# Job Create Assignee Dropdown Fix

Updated the Job create page so the assignee field is always populated from the active workspace members.

## Fixed

- The Job Assignee dropdown now uses one shared resolver for active workspace members.
- Admin and Super Admin can see all active workspace members in the Job Assignee dropdown.
- Normal users see their own account selected automatically when they do not have assignment permission.
- The dropdown is refreshed after the Job create modal is moved into the full-page record view, so the searchable dropdown UI and the native select stay in sync.
- The selected assignee is preserved and submitted as `owner_id` when creating the Job.

## Files changed

- `public/flowtrack-dynamic.js`
