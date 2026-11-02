# Order task number collision fix - 2026-11-03

## Problem

Order Workflow synchronization generated task numbers with `MAX(tasks.id) + 301`, rather than using the highest *existing task number*. Historical task identifiers can be numerically ahead of the associated database ID. The new Production task then attempted to use `TSK-13347` even though that identifier was already present. MySQL correctly rejected it (`tasks_task_number_unique`).

## Resolution

- Introduced a single-row `task_number_sequences` table. The migration seeds its counter above **every** historical numeric `TSK-` identifier (including soft-deleted tasks) and the previous `MAX(id) + 301` baseline.
- All new Order Task Pack tasks and manual Order tasks reserve numbers from the **same** sequence.
- Reservations use an indexed row lock within the caller's database transaction, so concurrent users cannot reserve the same number.
- Task-pack synchronization reserves numbers in **one batch per phase**, only for missing tasks. Normal reads that find every task already present do **not** update the sequence.
- Existing task IDs, task numbers, related documents, and task data are not changed.

## Deployment

1. Back up the database and deploy the changed files.
2. Run `php artisan migrate --force` before allowing task creation with the new code (briefly avoid concurrent task writes during the migration/cutover).
3. Run `php artisan optimize:clear` and `php artisan queue:restart` if applicable.
4. Rerun the Order Details screen and check the Production stage's `Set estimated delivery date` task.
5. Run `php artisan test --filter=OrderTaskNumberSequenceRegressionTest`.

**Never run** `migrate:fresh`, `migrate:refresh`, or `db:wipe` for this fix.

## Validation limits

PHP syntax and archive checks can run on the submitted source; runtime MySQL concurrency and Laravel integration tests require a configured local/staging environment with Composer dependencies and a database.
