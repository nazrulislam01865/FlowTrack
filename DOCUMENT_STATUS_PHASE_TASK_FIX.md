# Document Archive, Job Status & Phase Task Fix

## 1. Document Archive
- Removed the page-header `Upload Required Document` button.
- Replaced the oversized required-document card with the same compact attachment/file-list language used by Job and Task details.
- Added a compact `Select Job` attachment entry point so required documents are still linked to the correct Job/phase/task/category.
- Documents are shown as responsive file rows with category, Job/client, version, uploader/date, status, Open, Details and permission-aware Delete actions.
- Search/category/client filters remain available and are responsive.

## 2. Job Details task status
- Job Details phase-task status selectors are now native compact selects (`data-native-select`).
- They are excluded from the global searchable dropdown enhancer, so the large search panel and internal option scrollbar are not shown.
- The phase-task table no longer uses a forced 850px minimum width at tablet sizes.
- At phone widths task rows become responsive cards, so the section does not require horizontal scrolling.

## 3. Materialize all phase tasks at Job creation
- `WorkflowEngine::initialize()` now creates Task Pack tasks for the starting phase and every later active, non-skipped workflow phase in the same Job-creation transaction.
- Phases before a mid-workflow starting phase remain skipped and do not receive active tasks.
- `WorkflowEngine::move()` no longer generates target-phase tasks during normal phase movement. It moves the Job onto the Task set that already exists.
- Task creation totals by phase are retained in Job initialization audit metadata.

## Main changed files
- `app/Modules/OrderFlow/Business/Services/WorkflowEngine.php`
- `resources/js/flowtrack/modules/workspace.js`
- `resources/js/flowtrack/modules/job-details.js`
- `resources/css/flowtrack/modules/documents.css`
- `resources/css/flowtrack/runtime/13-system-responsive.css`
- generated `public/flowtrack/*` runtime files
- generated `public/build/manifest.json` / CSS asset
