# Create Order -> Order Workflow Setup binding

## Purpose

Create Order now reads only workflows published by the dedicated **Order Workflow Setup** screen. The original reusable **Workflow Setup** remains a separate administration feature and is not used as the source of Order workflow choices.

## Create Order behavior

- The `What happens next` section keeps the prototype-style workflow card.
- The card is selectable again.
- Options are loaded from `OrderWorkflowSetupService::dedicatedWorkflowQuery()`.
- Workflow name, stage count, task count, stage names, stage colors and per-stage task counts are rendered from the saved Order Workflow Setup record.
- `Preview workflow` expands a seven-stage preview using the saved configuration.
- New Orders always start at the first active stage of the selected Order workflow.
- If no dedicated Order workflow has been saved, the section shows a clear setup message and, for authorized users, a link to Order Workflow Setup.

## Separation from the original Workflow Setup

The generic Workflow Setup remains available at `/workflow-setup` and continues to manage its existing reusable workflow records. Create Order does not query or validate against those generic records when selecting an Order workflow.

## Query behavior

All active dedicated Order workflows are eager-loaded with active phases and Task Pack items in one render path. Stage/task counts and the preview use the loaded relationships; no per-stage or per-task database query is added in Blade.
