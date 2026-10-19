# Order Workflow live binding fix — 2026-08-21

## Problems corrected

1. Saving Order Workflow Setup could fail with `SQLSTATE[42S22]` because the three task-document option columns were missing from `task_pack_items`.
2. Order Details could continue rendering an older five-stage workflow snapshot instead of the dedicated seven-stage Order Workflow Setup.
3. New Orders were snapshotted immediately, so later Order Workflow Setup changes could not appear on active Orders.
4. The workflow section lost some of the prototype presentation while being converted from the browser-only prototype to backend-driven data.

## Current architecture

- Original **Workflow Setup** remains unchanged and reusable.
- **Order Workflow Setup** owns only the dedicated `ORDER_PROCESS` workflow.
- New Orders remain directly attached to the dedicated Order workflow while active.
- Saving Order Workflow Setup synchronizes all active Orders to the dedicated workflow.
- Completed/cancelled Orders keep historical workflow data.
- Old generated tasks are soft-deleted during a one-time rebind; manual tasks and Order documents are retained.
- Order Details renders stages from `job->workflow->phases` and tasks generated from the configured stage Task Packs.
- Stage/task Blade components contain presentation only; task sequencing remains in `OrderTaskSequenceService`.

## Database repair

An idempotent migration was added:

`2026_08_21_185800_ensure_order_workflow_document_options_on_task_pack_items.php`

It guarantees these columns exist:

- `document_required_before_completion`
- `allow_multiple_documents`
- `document_instructions`

`TaskPackService` also guards these fields during rolling deployments so old schemas no longer throw a raw SQL 500 before migration is applied.

## One-time synchronization

After deployment run:

```bash
php artisan migrate
php artisan optimize:clear
php artisan flowtrack:sync-order-workflow
```

The last command is safe to run again and synchronizes active Orders with the current dedicated Order Workflow Setup.
