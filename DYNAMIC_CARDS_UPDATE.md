# Dynamic Summary Cards Update

All summary and KPI cards now read from the current workspace database and respect the signed-in user's record visibility.

## Updated areas

- Management Dashboard: active Jobs, attention, overdue/blocked Tasks, shipping workload, receivables, phase counts, team workload, deliveries and activity.
- My Work: due today, overdue, blocked/attention, waiting review, completed this week and upcoming Tasks.
- Workflow Setup: active templates, configured phases, allowed starting stages and automatic transitions.
- Task Pack Setup: total/active Task Packs, configured Task Pack items and mapped Workflow Phases.
- Master Data: category count and workspace-wide record totals, with the selected category shown separately.
- Administration: active users, active operational roles, departments and configured notification rules.
- Reports: conversion, delivery, task completion, artwork cycle, shipment performance, receivables and ageing.

## Data and access behavior

- Dashboard, My Work and report figures are calculated only from Jobs and Tasks visible to the current user.
- Admin and Super Admin figures cover the complete workspace.
- Financial cards show `Restricted` when the user lacks invoice and sensitive-field access.
- Dates and week boundaries are calculated at runtime using the application timezone.
- Counts refresh from the backend bootstrap after create, update, delete, assignment and workflow operations.

No database migration is required for this update.
