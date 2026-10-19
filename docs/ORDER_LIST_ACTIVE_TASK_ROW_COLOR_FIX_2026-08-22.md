# Order list active-task row color fix — 2026-08-22

## Problem
Order rows were not visibly changing color when the active workflow task changed. The previous implementation applied a very light `color-mix()` background to the `<tr>` element. In table rendering, that tint could be hidden by cell backgrounds or appear too subtle. Existing Order workflow snapshots could also retain an older copied task color instead of the current presentation color configured on the source Task Pack item.

## Fix
- The active task is still resolved from the current workflow phase and now also handles manually-added active tasks consistently with Order Details.
- For snapshotted Orders, the list resolves the presentation color from the live source Task Pack item first, then falls back to the snapshot/legacy task color. This changes presentation only; workflow rules remain snapshot-protected.
- Added a reusable `MasterColor::taskRowStyle()` helper that emits browser-safe RGB row variables.
- Applied the task tint directly to every table cell instead of only the `<tr>`.
- Added a stronger left accent using the exact configured task color.
- Hover uses a slightly stronger version of the same task color.
- Rows without a configured task color remain neutral.
- Updated the prebuilt Orders CSS asset and Vite manifest so the fix is immediately visible without rebuilding frontend assets.

## Scope
No workflow progression, task completion, branching, permissions, filtering, stage logic, or assignment logic was changed.
