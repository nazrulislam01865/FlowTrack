# My Work auto-creation / read-only repair

- My Work now renders only from the authenticated database snapshot. It never falls back to prototype/demo Jobs or Tasks.
- Historical demo operational records are cleared before the production runtime can render them.
- `GET /flowtrack/template-api/my-work-snapshot` is strictly read-only.
- Job Details GET and Job Tasks GET no longer materialize, restore, synchronize, create or delete Tasks.
- New Jobs still materialize all configured non-skipped phase Tasks in the existing Job creation transaction.
- Explicit Task Pack synchronization remains a POST action and is the only compatibility path that may reconcile setup Tasks.
- My Work browser cache schema was bumped to v4; older cached snapshots are ignored.
- Cached My Work snapshots must identify themselves as database/read-only snapshots before they can be restored.
- Regression tests assert that My Work and Job Details GET requests do not change Job/Task counts.
