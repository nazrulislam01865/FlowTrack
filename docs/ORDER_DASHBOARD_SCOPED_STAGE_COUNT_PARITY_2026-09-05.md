# Orders dashboard-scoped stage count parity — 2026-09-05

## Problem

When the Orders page was opened from a Dashboard workflow-stage card, the table respected the Dashboard date/client/team scope but the stage cards still used the normal all-orders totals. This could show, for example, 8 Billing orders on the card while the scoped Billing table contained 7.

## Fix

- Added an exact-date `dashboardScopedStages()` read path.
- Orders stage cards now use that path whenever `dashboard_scope=1`.
- The count scope uses the same active-client, `flow_jobs.updated_at`, Client and owner-Team constraints as the Dashboard-scoped Orders table.
- Cache keys include the exact local date range, Client and Team to prevent cross-scope count reuse.
- Normal Orders-page stage cards keep their existing full current-stage totals.
