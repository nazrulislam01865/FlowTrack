# Order Summary Report client checkbox filter — 2026-09-03

## Scope

Added a multi-select client filter to the Order Summary Report using compact checkbox cards that match the existing FlowTrack report typography and control language.

## Behaviour

- No checked client means all clients remain visible.
- One or more checked clients filter the report with a single `whereIn(flow_jobs.client_id, ...)` scope.
- Pagination resets whenever the client selection changes.
- Quick counts use the same selected client scope.
- Excel export receives and validates the same `client_ids[]` values, so the exported rows match the visible report.
- Reset clears the client selection together with the existing report filters.

## Visibility and performance

Client choices are derived from the same permission-scoped active Order query as the report. The option query is a single SQL query with a distinct client-id subquery, so client names outside the user's Order visibility scope are not exposed.

The client row is horizontally scrollable to avoid growing the report panel vertically when a workspace has many clients.
