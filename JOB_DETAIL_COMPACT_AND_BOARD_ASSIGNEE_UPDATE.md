# Job Detail Compact and Board Assignee Update

- Compacted the reference Job Details layout spacing, metric cards, timeline, task rows, side cards, document panels, and activity rows without changing the requested screen structure.
- Added a fresh `boardAssigneeUsers` bootstrap payload so Admin and Super Admin see every active workspace member in the Board assignee filter.
- Regular users still receive only their own account in the Board assignee filter.
- Frontend now prefers `boardAssigneeUsers` for the Board filter and falls back safely to the normal `users` collection.
