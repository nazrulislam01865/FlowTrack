# Board Assignee Role Filter and My Quick Label Update

## Changes

- Board Assignee filter now follows role rules:
  - Admin and Super Admin see every active workspace user.
  - Regular users see only their own name in the Assignee filter.
- Quick filter label now changes by board mode:
  - Job Board: `My job`
  - Task Board: `My task`
- Board visibility remains role-aware:
  - Admin and Super Admin receive all workspace Jobs and Tasks through the existing authorization layer.
  - Regular users remain limited to their assigned Job/Task scope.
- Assignee filter matching now uses the effective Task assignee, including Task Pack default assignee when no manual override exists.

## Files changed

- `public/flowtrack-dynamic.js`
- `app/Http/Controllers/FlowTrack/TemplateApiController.php`
