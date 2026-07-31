# Board bulk action and assignee filter update

Implemented requested UI and filtering updates:

- Compact Jobs page bulk selection bar and buttons.
- Removed `Saved views` and `View settings` from the Operations Board header.
- Added an `Assignee` filter after the `Client` filter on the Operations Board.
- Assignee filter applies to both Job Board and Task Board:
  - Job Board matches job owner/coordinator and any task assignee inside the job.
  - Task Board matches the effective task assignee, including default setup assignees.
- Kept existing backend bulk deactivate/delete behavior.

Validation:

- `node --check public/flowtrack-dynamic.js`
- `php -l` for application PHP files
- `node scripts/verify-assets.mjs`
