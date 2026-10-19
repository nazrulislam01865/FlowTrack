# My Tasks assignee-only scope for all roles — 2026-08-31

## Problem

The **My Tasks** page was using a visibility-oriented Order task scope. Non-admin users could receive the current active task because they were the assignee, the Order creator, or had broader configured record access. Admin and Super Admin bypassed that visibility gate entirely. As a result, My Tasks could behave like an operational task overview and show Orders/tasks that were not assigned to the signed-in user.

The filtered Inquiry-task path had the same conceptual issue because it reused general Inquiry task access rules, which can include creator, department, or all-record access.

## Required behavior

My Tasks is a personal execution queue. For every role — normal user, Admin, and Super Admin — a task belongs on My Tasks only when `assignee_id` is the authenticated user's ID.

The existing active-workflow behavior is preserved: future, sibling, completed, stale Task Pack, and inactive Order rows remain excluded until they are the structurally active task.

## Implementation

- `MyWorkService::activeVisibleTaskQuery()` now applies an unconditional `tasks.assignee_id = current_user_id` constraint before the existing active-workflow constraints.
- Order creator/owner access, configured record scopes, Admin privileges, and Super Admin privileges no longer add unrelated Order tasks to My Tasks.
- The same service scope continues to drive:
  - grouped Order results;
  - workflow-stage counters;
  - My Tasks metrics;
  - the sidebar My Tasks count;
  - inline-edit authorization lookup on the page.
- `LegacyInquiryService` now centralizes the My Tasks Inquiry scope in `assignedInquiryTaskQueryForMyWork()`, layering `inquiry_tasks.assignee_id = current_user_id` on top of normal permissions.
- Inquiry My Task groups, metrics, and open-task count all reuse that helper.
- Inline Order reassignment now re-checks list membership for **every** role. If an Admin/Super Admin reassigns their current My Tasks row to another user, the page refreshes and removes that row immediately.
- Page copy now explicitly states that Admin and Super Admin follow the same personal assignment rule.

## Scope intentionally unchanged

- Global Order/task permissions are unchanged.
- Admin/Super Admin keep their broader permissions everywhere outside My Tasks.
- All Tasks, Order Details, Dashboard, and other operational views are not converted to personal queues.
- Workflow sequencing and active-task resolution remain unchanged.
- No database migration or frontend asset rebuild is required.
