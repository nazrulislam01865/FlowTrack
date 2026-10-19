# Inquiry Taskflow compact status/resource cluster — 2026-08-29

## Problem
On narrower Inquiry Details layouts, the per-task **+ File** and **+ Link** actions could drift away from the task status selector. On tablets and small devices this made one task feel visually split into unrelated controls.

## Fix
- Added a reusable `ft-inquiry-task-status-resources` wrapper around the existing status and resource controls.
- Desktop layout remains unchanged through `display: contents`.
- Narrow Taskflow containers render status, + File, + Link, and resource count as one responsive control cluster.
- Added a protected medium-width layout for 1181–1240px Taskflow containers so tablet/small-laptop widths do not fall between the existing breakpoints.
- On phones the cluster stays together, wraps safely when needed, and the final task action moves below it.
- Added a viewport fallback for browsers without container-query support.

## Scope
No task status, upload, link, permission, completion, Livewire, or persistence behavior was changed.
