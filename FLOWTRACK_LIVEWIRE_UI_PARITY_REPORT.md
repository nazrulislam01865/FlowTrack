# FlowTrack Full Livewire UI Parity Correction

Date: 2026-08-03

## Why the previous Livewire package looked different

The previous migration converted the application behavior to Livewire, but it also rebuilt many Blade views with simplified replacement markup. That was the wrong approach for this project because the existing FlowTrack design is strongly tied to the original DOM hierarchy, page IDs, class names, card/table wrappers, record shells, and responsive selectors.

The original CSS was largely still present, but selectors such as `#page-board`, `#page-jobs`, `#page-masterdata`, `.standalone-record-page`, `.ops-board-canvas`, `.jobs-v2-*`, `.job-v4-*`, `.ft-task-*`, `.master-*`, and `.access-*` no longer matched the replacement markup. The visual result therefore changed even though much of the CSS had not been intentionally redesigned.

## Correction strategy

The previous project (`Archive(12).zip`) is now treated as the visual source of truth.

The corrected migration follows these rules:

1. Keep the original FlowTrack stylesheet and responsive runtime rules.
2. Keep the original page IDs, DOM hierarchy, design class names, section order, card/table/board structure, standalone record shells, toolbar structure, and mobile hooks.
3. Replace JavaScript rendering/event handling with Livewire state, `wire:*` bindings, server pagination, and Laravel Actions/Services.
4. Do not reintroduce the old 10,000+ line JavaScript renderer.
5. Keep existing backend authorization, workflow, task, document, notification, and audit logic authoritative.
6. Lazy-load/paginate records instead of downloading the workspace after login.

## Original design assets preserved

All 30 original FlowTrack CSS module/runtime files used by the authenticated application are byte-for-byte identical to the previous project.

The only additions are:

- `modules/browser-compatibility.css`
- `modules/livewire-runtime.css`

Those files contain compatibility and behavior-neutral helpers needed for native/Livewire interactions. They do not replace the original visual system.

## Page-by-page UI restoration

| Screen | Restored design structure | Livewire/backend behavior |
|---|---|---|
| Dashboard | Original metrics, attention/activity/workload card structure | Metrics and links are server-driven |
| My Work | Original Job-grouped Task board/cards/status columns | Server filtering and pagination |
| Jobs | Original `jobs-v2` filter bar, bulk bar, table, badges and progress cells | Search/filter/bulk actions/pagination through Livewire |
| Job Create/Edit | Original standalone record form, product rows, workflow preview, attachment area and footer | Validation, creation/update and uploads through Laravel actions |
| Job Details | Original v4/v5/v6 header, metrics, overview, workflow, commercial, documents, activity/history layout | Tab-level lazy queries and backend mutations |
| Clients | Original client-card grid | Server search/filter/pagination |
| Client Create/Edit/Details | Original standalone record shell and detail sections | Livewire validation and protected backend changes |
| Operations Board - Jobs | Original `ops-phase-kanban` Job cards and workflow controls | Backend workflow transition rules |
| Operations Board - Tasks | Original `ops-board-canvas`, sticky status header, Job groups, Task rows and cards | Server pagination; status actions moved into the existing card action menu rather than JS drag/drop |
| Documents | Original Document Archive upload/drop-zone and document-card grid | Server upload/link/delete/preview logic |
| Task Details | Original six-field metadata area, checklist, attachments, activity tabs and Job context | Status/due/assignee/checklist/document actions use existing backend actions |
| Reports | Original KPI/report-grid information architecture | Server report queries, print view and CSV generation |
| Notifications | Original attention-list presentation | Server pagination/read state |
| Workflow Setup | Original workflow/configuration layout and phase table | Livewire create/edit/reorder/validation |
| Task Pack Setup | Original Task Pack list and standalone item editor | Livewire create/edit/reorder/validation |
| Master Data | Original left category rail, summary and master table | Livewire create/edit/status/delete |
| Administration | Original role cards, role filters, permission matrix, sensitive switches and member table | Server role/user/permission updates |
| Profile | Original two-column profile/account layout | Server profile/password/avatar changes |

## Task Board parity correction

The earlier Livewire package used a generic column grid on Task Board. That has been removed.

The corrected Task Board again uses the previous project's structural design:

- `.ops-board-canvas`
- `.ops-status-grid`
- `.ops-status-head`
- `.ops-job-group`
- `.ops-job-row-head`
- `.ops-task-row`
- `.ops-task-cell`
- `.ops-task-card`

Tasks are grouped by Job on the current server-paginated slice. The original card action area is used for Livewire status changes/deletion, which avoids reintroducing custom drag/drop JavaScript.

The Board also no longer incorrectly hides completed Tasks. It limits Task Board data to active Jobs while keeping status columns available, matching the previous Board logic more closely.

## Backend business logic retained

The migration does not move business rules into Blade or browser code. Existing Laravel Actions/Services remain the source of truth, including:

- `CreateJobAction`
- `UpdateJobAction`
- `MoveJobPhaseAction`
- `TaskTransitionAction`
- `UpdateTaskDueDateAction`
- `RemoveTaskFromJobAction`
- `UploadDocumentAction`
- `DeleteDocumentAction`
- `LinkStoredDocumentAction`
- `WorkflowEngine`
- `PhaseReadinessEvaluator`
- `FlowAuthorizationService`

This keeps assignee restrictions, Admin/Super Admin overrides, workflow gates, required documents, blockers, audit history, notifications and permission checks on the Laravel backend.

## JavaScript reduction

Active authenticated UI source validation:

- custom application JavaScript files: **0**
- `wire:ignore` references: **0**
- inline `<script>` blocks in Livewire/layout views: **0**
- old `flowtrack-dynamic-source`/`WorkspaceShell` references: **0**
- custom CSRF refresh/fetch-repair references: **0**

Livewire's own browser runtime remains because it is required for server-rendered component updates.

## CSRF/session handling

Laravel/Livewire CSRF protection remains enabled. The unsafe approach would be to remove CSRF from a session-authenticated application. What has been removed is the custom JavaScript token-repair system and global fetch replacement. Normal Laravel/Livewire session/XSRF handling is used instead.

## Lazy loading / efficiency

The UI no longer relies on the old JavaScript bootstrap that downloaded and rendered the workspace in the browser.

Examples:

- Jobs and Tasks are paginated by Laravel.
- Board data is limited to the current server page.
- Job Details loads Tasks/Documents/History only for the selected tab.
- Task Details paginates Documents and Activity independently.
- setup/master/admin screens query their own required data.
- login does not load the workspace application renderer.

## Validation performed

Static source/build validation completed successfully:

- **20** Livewire page views checked for required original design hooks: no missing required hooks.
- **104** explicit Livewire handlers checked against component public methods: no missing handlers.
- **30** original FlowTrack CSS module/runtime files compared against the previous project: all identical.
- **200** application/config/route/database/script/test PHP files passed `php -l` in the final validation pass.
- `composer.json` valid JSON.
- `package.json` valid JSON.
- `vite.config.js` syntax valid.
- prebuilt FlowTrack CSS manifest verified by `scripts/check-vite-build.php`.
- generated CSS contains no unresolved `@import` statements.

## Runtime validation limitation

The uploaded projects do not contain `vendor/` or `node_modules/` in this environment. Therefore a fully booted Laravel browser session, Laravel test suite, Livewire DOM test, and screenshot/pixel-diff comparison could not be run here.

For that reason this report does **not** claim browser screenshot pixel-perfect verification. The correction is based on direct source comparison with the previous project's design markup/classes/CSS, static handler validation, PHP syntax validation and packaged asset validation.

After deployment, run the normal dependency/build process and perform a final browser visual pass against the previous project.

## Deployment commands

```bash
composer install --no-dev --optimize-autoloader
npm ci --no-audit --no-fund
npm run build

php artisan optimize:clear
php artisan migrate --force
php artisan storage:link

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart

php scripts/check-vite-build.php public/build
```
