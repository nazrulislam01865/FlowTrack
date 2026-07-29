# Job Assignment Visibility and Controls Update

## What changed

This update makes Job access strictly assignment-based for every non-administrator.

A user can see a Job only when at least one of these conditions is true:

- the user created the Job;
- the user is the Job owner;
- the user is the Job coordinator;
- the user is included in the assigned Job team; or
- the user is assigned to at least one Task in the Job.

Administrators retain workspace-wide access. Role scopes such as `all_records` no longer expose unrelated Jobs to non-administrators.

## Task visibility inside an assigned Job

Every person involved in a visible Job can see all of its Tasks and subtasks, including Tasks assigned to other members. This rule is applied consistently to the Jobs drawer, My Work, Task Board, dashboard metrics, global search, reports and the exact-template interface.

Viewing a Task does not automatically grant full edit access. Full status, checklist, blocker and comment updates remain limited to the Task assignee or an Administrator under the existing permission matrix.

## Due-date changes

A dedicated due-date action is available for regular Tasks and subtasks. It can be used by:

- Administrators;
- the Job creator;
- the Task assignee when their role permits Task updates; and
- the Job owner, coordinator or a Job member with Task-management access when their role permits the relevant Task action.

Every due-date change is written to the activity audit trail.

## Task removal

Administrators and the original Job creator can remove any Task from the Job. Removing a parent Task also removes its subtasks and safely clears surviving parent/dependency references.

A Task generated from a Task Pack is marked as intentionally removed before soft deletion. Task Pack synchronization will not restore it, and readiness/document gates treat the removal as an authorized Job-level exception.

## Job-specific phase removal

Administrators and the original Job creator can remove a workflow phase from one Job. This does not delete or modify the shared workflow template.

When a phase is removed:

- Tasks belonging to that phase are intentionally removed and will not regenerate;
- documents remain in the archive but are detached from the removed phase/task requirement;
- the phase is excluded from that Job's timeline, move targets and automatic advancement;
- if it was the current phase, the Job moves to the next remaining active phase, or the nearest previous active phase when no later phase remains; and
- at least one active phase must remain in the Job.

The action and optional reason are recorded in the activity and transition audit trails.

## Database update

Run the normal deployment migration:

```bash
php artisan migrate --force
php artisan optimize:clear
```

The migration:

- records the original Job creator in `flow_jobs.created_by`;
- backfills existing Jobs from their `job.created` audit event, then falls back to owner/coordinator;
- adds intentional Task-removal metadata; and
- creates `flow_job_phase_exclusions` for per-Job phase removal.

Do not run `migrate:fresh` on an existing installation.
