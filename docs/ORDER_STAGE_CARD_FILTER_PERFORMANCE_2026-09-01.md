# Order stage-card filter performance — 2026-09-01

## Problem
Clicking workflow stage cards on Orders and My Tasks felt slow, and dashboard stage navigation waited for the same expensive Orders render.

## Root causes
- Stage counts were recalculated on every Livewire render even though selecting a card does not change those counts.
- My Tasks resolved canonical stage aliases through a correlated `workflow_phases` relation filter using `LOWER(TRIM(...))` for both the grouped paginator and task hydration query.
- Historical/snapshot workflow phase IDs were rescanned from the full phase table on each stage click.
- Dashboard stage links did not prefetch the Orders page before navigation.
- The hot stage-filter columns did not have composite indexes aligned to these exact access patterns.

## Fix
- Added short-lived, user/workspace-scoped caches for Orders, Dashboard and My Tasks stage-card counts.
- Cached the canonical stage-to-phase-ID map for 10 minutes.
- My Tasks now filters directly on indexed `tasks.workflow_phase_id` after resolving canonical aliases.
- Dashboard/navigation stage links use `wire:navigate.hover` so the filtered Orders page starts loading before click.
- Added composite indexes for `flow_jobs.source_workflow_phase_id` open-order filtering and `tasks.assignee_id + workflow_phase_id` personal stage filtering.

## Deployment
Run `php artisan migrate --force` after deploying this version so the new indexes are created. No frontend build is required.
