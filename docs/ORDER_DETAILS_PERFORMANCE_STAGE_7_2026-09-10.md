# FlowTrack Order Details Performance — Stage 7A / 7B

Date: 2026-09-10

## Scope

This pass is intentionally limited to two measured issues:

1. `jobs.order-workflow-section::updateTaskAssigneeFromJob`
2. stale protected `rich-text-images.show` requests returning 404

No workflow-stage rules, required/optional task rules, hold/unhold behavior, database schema, or UI design were changed.

## Evidence before Stage 7

The supplied local performance log captured one task-assignee update at:

- total: `312.41 ms`
- queries: `270`
- query time: `181.83 ms`
- non-DB time: `130.58 ms`
- cache hits: `73`
- cache misses: `19`
- cache writes: `7`
- cache forgets: `60`

The same request included repeated work such as:

- 92 database-cache reads
- 60 database-cache deletes
- repeated `flow_jobs` reads
- repeated `tasks` reads
- four notification inserts
- four repeated role lookups

The supplied log also showed stale rich-text image routes returning 404, including requests around `10.72 ms` and `4.29 ms` locally.

## Stage 7A changes

### Narrow assignment service path

`updateTaskAssigneeFromJob()` now loads the Task with the exact relations needed by assignment/audit/notification handling and calls `TaskService::updateAssignee()` rather than the general `updateDetailField()` path.

The dedicated assignment method preserves:

- assignment authorization
- active-user validation
- Task `assignee_id` update
- `FlowJobMember` synchronization
- Task activity history
- Order activity history
- assignment notifications
- realtime notification delivery
- materialized Next Action assignee synchronization

It intentionally does not execute status/flag/progress/stage recalculation because `assignee_id` cannot change those values.

### Request-local model reuse

The Order task action preloads:

- current assignee
- parent Order
- Order members

The saved Task is reused for the JSON response rather than being refreshed again only to build the assignee payload.

`AccessControlService::isTaskParentCreator()` reuses a loaded parent Order when present and falls back to the original database query for all other callers.

### Notification fanout reuse

The task-notification fanout now:

- reuses the already loaded parent Order instead of re-querying it for every recipient
- eager-loads recipient roles in one fanout query
- retains the existing per-recipient visibility check before creating a notification

### Redundant cache deletion removal for this mutation only

The captured assignment request performed 60 explicit cache deletes. This corresponds to recipient cache invalidation repeated during notification fanout.

For the dedicated Order assignment mutation only, those explicit notification-recipient cache deletes are skipped. This is safe for this path because Task and Activity writes are observed by `WorkspaceDataObserver`; the transaction already advances the workspace data version after commit, and the dashboard/report/shell caches are version-keyed by that workspace version.

All other notification call sites keep the original cache invalidation behavior by default.

## Stage 7B changes

`StoredAssetUrlService` now supports protected rich-text images stored through `SecureDocumentStorage`.

`RichTextService::safeHtml()` checks physical existence only while preparing content for presentation. Missing rich-text image tags are suppressed before HTML is emitted to the browser.

The original stored rich-text source is not rewritten merely because a file is temporarily or permanently missing. `normalize()` keeps the controlled image reference; only presentation removes the stale URL.

`imageAttachments()` also excludes missing rich-text images, so compact Open/Download rows cannot emit a known-broken protected URL.

## Files changed

- `app/Livewire/Jobs/Concerns/ManagesOrderTasks.php`
- `app/Services/AccessControlService.php`
- `app/Services/NotificationService.php`
- `app/Services/RichTextService.php`
- `app/Services/StoredAssetUrlService.php`
- `app/Services/TaskService.php`
- `tests/Feature/RichTextImagePasteSupportTest.php`
- `tests/Feature/OrderTaskAssignmentPerformanceStage7Test.php` (new)

## Validation performed in build environment

The uploaded archive does not contain `vendor/`, so Laravel PHPUnit cannot be executed here.

Performed successfully:

- PHP syntax validation for all `app/` and `tests/` PHP files: 702 files, zero syntax errors
- Stage 7 source-contract checks: PASS

## Local test commands

```bash
php artisan optimize:clear

php artisan test --filter=OrderTaskAssignmentPerformanceStage7Test
php artisan test --filter=RichTextImagePasteSupportTest
php artisan test --filter=OrderTaskAutomaticAssigneeTest
php artisan test --filter=InlineEditingMechanismTest
```

No frontend source changed in Stage 7, so a Vite rebuild is not required for this pass.

## Runtime verification

Keep the performance monitor enabled and run:

```bash
tail -f storage/logs/laravel.log
```

Then on the same Order used for the baseline:

1. change a task assignee
2. change it again
3. unassign if supported
4. verify the Task activity entry
5. verify the Order activity entry
6. verify the expected users receive notifications
7. verify Next Action shows the new assignee
8. verify task status/progress/stage did not change merely because the assignee changed

Compare the new `updateTaskAssigneeFromJob` line with the Stage 7 baseline of `270 queries / 312.41 ms / 181.83 ms SQL`.

For Stage 7B, open an Order/activity that previously referenced the missing rich-text image and confirm there is no `rich-text-images.show` 404 in Network or `laravel.log`.

Do not claim a final after-number until this runtime test is captured on the user's local environment.
