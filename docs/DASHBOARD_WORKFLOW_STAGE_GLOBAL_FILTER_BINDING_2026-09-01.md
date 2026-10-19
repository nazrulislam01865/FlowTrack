# Dashboard workflow-stage cards: global filter binding fix

Date: 2026-09-01

## Problem

The seven workflow-stage cards at the top of the Dashboard always showed the all-time/current Orders-page totals. The Dashboard controls (`Today`, `7 days`, `30 days`, Client, and Team) updated other Dashboard read models, but these cards were populated with `OrderListQuery::stages($user)`, which intentionally has no Dashboard filter context.

As a result, changing the Dashboard period, Client, or Team could leave values such as `New Order 271` and `Artwork 81` unchanged even though the rest of the Dashboard was scoped differently.

## Fix

A dedicated filter-aware stage-count path was added without changing the Orders-page behavior:

- `OrderListPrototypeService::dashboardStages(...)` applies the Dashboard period, Client, and Team constraints before the existing grouped stage-count query runs.
- The period uses workspace-local calendar bounds and the same `flow_jobs.updated_at` semantics used by the Dashboard summary and flow-distribution sections.
- Client filtering uses `flow_jobs.client_id`.
- Team filtering uses the Order owner's `department_id`, matching the Order-level Dashboard filters.
- Only operational Orders are counted: completed, inactive, and cancelled Orders remain excluded.
- The existing `OrderStageResolver` is still used, so historical five-stage/legacy phase rows continue to map into the canonical seven-stage runtime contract.
- `OrderListQuery::stages()` is unchanged for the Orders page and My Tasks flows.
- `Dashboard\Index` now calls `OrderListQuery::dashboardStages(...)` with the current `rangeDays`, Client, and Team values.

## Expected behavior

Changing any of these controls now recalculates all seven stage-card counts on the same Livewire request:

- Today
- 7 days
- 30 days
- Client
- Team

Selecting `All clients` or `All teams` removes only that dimension while preserving the selected period.

## Validation

A regression test (`DashboardWorkflowStageGlobalFiltersTest`) verifies the filter-aware binding and confirms that canonical seven-stage mapping remains in place. PHP syntax validation is also run across the changed files/project before packaging.
