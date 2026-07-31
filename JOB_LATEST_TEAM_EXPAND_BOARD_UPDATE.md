# Job Latest Ordering, Assigned Team Header, and Board Expand Controls

- Jobs page now sorts newest created Jobs first using createdAt/dbId, not delivery date.
- New Job creation removes any matching stale in-memory row before inserting the returned Job at the top, preventing duplicates or overwrites.
- Job Details header team bubbles now show only users assigned to Tasks in that specific Job.
- Job Details edit/action buttons are shown only to Admin/Super Admin or a user assigned to a Task in that Job, while backend permission checks still apply.
- Board page now has an Expand all jobs / Minimize all jobs toggle.
- In Task Board mode it expands/collapses all job task groups.
- In Job Board mode it expands/minimizes all job phase cards.
- Board Assignee filter is strengthened so Admin/Super Admin options are sourced from the backend list, access members, users, job owners/coordinators, and effective task assignees.
