# FlowTracker — Task 2: Order-owned workflow definitions

## Scope

Only Task 2 is addressed. The input archive's corrected Part 1 Order Details loading, existing workflow actions, supplier sync, PO disablement, styles, routes and database schema are unchanged.

## Verified existing implementation (no redesign)

- `LegacyJobService::create()` already wraps Order creation, Order-specific `tasks` generation, phase history, shipments and related writes inside one `DB::transaction`.
- Generated runtime Tasks already have `flow_job_id`, `workflow_phase_id` and `task_pack_task_id` and belong to their Order.
- `loadVisibleOverviewWorkflow()` already loads saved Order Tasks, with the existing permission-scoped display projection. Task rows are **not** rebuilt from templates merely to render the page.
- The legacy task creation loop intentionally remains `firstOrCreate`/per-task ORM writes: it reserves globally unique task numbers, synchronizes flags/status, enforces idempotence, creates comments/memberships and triggers after-commit notifications. Bulk raw `Task::insert()` is **not** added because it would bypass these behaviors.

## Added (optional, for NEW standard Orders only)

The new `FLOWTRACK_ORDER_TEMPLATE_VERSION_ISOLATION_ENABLED` flag defaults to **false**.

When enabled **before creating a new standard Order**:

1. `LegacyJobService::create()` validates the selected published Order workflow as before.
2. Inside the same Order creation transaction, it calls the existing `JobWorkflowSnapshotService` to clone the **selected seven published** workflow phases, Task Packs and Task Pack items into a private Order-owned definition.
3. It generates the Order's normal runtime Tasks against the new Order-owned IDs, preserving assignee, status, action automation keys and attachment relationships. The Order records its original template via `source_workflow_id` / `source_workflow_phase_id`.
4. Ordinary Workflow Setup publishes and Order Details synchronization skip those frozen Orders. Edits to the original Task Pack do not change the frozen Task Pack item attributes.
5. The already-configured `OrderWorkflowDisplayQuery`, read snapshot and Part 1 selected-phase/resource loading can use frozen Order definitions without reading newly edited template data.

Each new frozen Order has its own immutable operational definition, rather than creating one additional editable global template version. This deliberately avoids changing setup UI or global templates.

### Limitations / preservation

- **Existing Orders are not migrated** or frozen automatically; they retain their previous live published-template semantics. This is deliberate to avoid breaking already-running Orders or attached evidence.
- **Redo Orders retain the existing Redo creation contract**; the new freeze flag applies to standard `LegacyJobService::create()` only. No Redo code was changed.
- There is **no UI action to migrate/rebind an existing frozen Order** to a new template. That would require a separately authorized, transactional migration design with task/evidence mapping; do not use normal template publish for this.
- Raw batch inserts were NOT added. They are not necessary for Order ownership and would risk existing side effects.
- The flag is default-off to prevent unintended rollout. Once frozen, an Order remains a saved snapshot even when the flag is disabled; disabling the flag only stops creation of new frozen copies and restores legacy read fallback for these copies.
- Creating private Task Packs and item mirrors for each new Order increases data writes/storage at creation. Benchmark this on staging before production enablement.

## Files changed

- `app/Services/LegacyJobService.php`: one guarded Create Order branch and frozen-Order display path.
- `app/Services/JobWorkflowSnapshotService.php`: reuse snapshot logic with explicitly selected published Order phases (instead of obsolete mirror phases).
- `app/Services/OrderWorkflowBindingService.php`: preserve frozen definitions on normal template publish and Order Details load.
- `app/Services/Orders/OrderWorkflowSnapshotService.php`: allow existing versioned display snapshot for frozen Orders when opted in.
- `config/flowtrack.php`: new flag, OFF by default.
- `tests/Feature/OrderTaskTemplateVersionIsolationTest.php`: feature regression cases (must run on a testing database).

## Staging validation (before enabling)

1. Deploy the full application with the flag **false**. Run the current project test suite and verify a standard Order/Redo Order still behaves exactly as before.
2. On a **non-production environment** with a backup, set `FLOWTRACK_ORDER_TEMPLATE_VERSION_ISOLATION_ENABLED=true`, then refresh config using your existing deployment procedure.
3. Run `APP_ENV=testing php artisan test --filter=OrderTaskTemplateVersionIsolationTest` with the project's isolated test database.
4. Create a new Order; verify seven stage IDs are private to this Order; Tasks, document upload, sample/conditional tasks, auto-advance, email, supplier projection, Reverb, summary/snapshot and the disabled PO behavior all work as before.
5. Change a source Task Pack title/assignment/sequence; verify the first Order retains its original copy while a newly created second Order gets the updated source.
6. Open a **previously existing** Order; its workflow must remain unchanged by this Task 2 deployment.
7. Compare SQL/count/latency of initial GET, Workflow view, modal, auto-advance, task edits and Order creation against the previous archive.
8. Do not enable on production until this regression test set and live integration checks are complete.

## No schema migration or production deployment

The implementation reuses existing `is_snapshot` / `snapshot_job_id` columns and services; it does not migrate historical Orders or change the Supplier Portal application. No automated Laravel test was executed in the code packaging environment because `vendor/` and the testing database were not supplied. Only PHP syntax and archive/static integrity checks were performed.
