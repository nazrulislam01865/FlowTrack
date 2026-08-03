# FlowTrack Full Livewire Migration Report

Date: 2026-08-02

## Executive summary

The authenticated FlowTrack frontend has been converted from a JavaScript-rendered hybrid into a **Laravel 13 / Livewire 4 full-page application**.

The supplied archive previously contained Livewire screen wrappers, but those wrappers were not the real UI. The old screen templates used `wire:ignore` and a JavaScript renderer (`flowtrack-dynamic-source.js`, 10,784 lines in the supplied archive) built and mutated the workspace after the page loaded. Core UI state, filtering, boards, record edits, modal behavior, status updates and large data bootstrap operations were therefore browser-driven.

The new build removes that architecture. Canonical authenticated routes now point directly to Livewire page components. Laravel queries load the active page only, and existing backend Actions/Services remain authoritative for workflow, permissions, documents, notifications and audit behavior.

## Before vs after

### Before

- Livewire acted as a screen shell rather than the actual application UI.
- Screen views were protected by `wire:ignore`.
- A ~10.8k-line JavaScript source rendered the application DOM.
- Browser code owned substantial page state and API orchestration.
- Data could be fetched into browser memory and then filtered/rendered there.
- A custom fetch/CSRF recovery layer previously attempted to repair 419 responses.
- Login imported the full workspace stylesheet.

### After

- Dashboard, My Work, Jobs, Job Form/Details, Task Details, Clients, Board, Documents, Reports, Notifications, Workflow Setup, Task Pack Setup, Master Data, Administration and Profile are native Livewire pages.
- `Route::livewire(...)` is the canonical authenticated routing model.
- There are **zero custom application JavaScript files** under `resources/js` or `public`.
- No new Livewire page contains `wire:ignore`, `<script>`, inline `onclick/onchange/oninput/onsubmit`, or a dependency on `/flowtrack/template-api/*`.
- Livewire's own framework runtime remains, because that is the transport/runtime required for Livewire updates.
- Login is a native Laravel form and has its own small CSS entry point.
- Core application mutations are executed by Laravel Actions/Services.

## Canonical Livewire pages

The migration contains 20 page views and 20 concrete page components plus the shared `BasePage` component.

- Dashboard
- My Work
- Jobs list
- Job create/edit
- Job details
- Task details
- Clients list
- Client create/edit
- Client details
- Job/Task Board
- Documents
- Reports
- Notifications
- Workflow Setup list/form
- Task Pack Setup list/form
- Master Data
- Administration / role matrix / users / branding / notification rules
- Profile

The previous `Screen`, `WorkspaceShell`, ignored screen templates, workspace shell Blade, prototype fallback, JavaScript bridge, dynamic renderer and custom searchable-dropdown runtime were removed from the canonical UI.

## Business logic preserved on the backend

The migration intentionally does **not** rewrite business rules inside Livewire views. Livewire calls the existing backend application layer.

### Tasks

Task status changes continue through `TaskTransitionAction`, preserving:

- effective-assignee authorization;
- Admin / Super Admin override;
- dependency checks;
- blocker rules;
- required-document prerequisites;
- checklist behavior;
- completion locking;
- audit/history writes;
- notification dispatch;
- workflow synchronization.

Due-date changes continue through `UpdateTaskDueDateAction`.

Generated Task Setup assignees are treated as effective assignees when an explicit override does not exist. Saving a task as a normal assignee does not silently convert a Task Setup assignment into an administrative override.

### Jobs and workflow

- Job creation uses `CreateJobAction` and the workflow engine.
- Job edits use `UpdateJobAction`.
- Phase movement uses `MoveJobPhaseAction`.
- Manual Task creation uses `CreateTaskAction`.
- Job deletion uses `DeleteJobAction`.
- Board phase choices are constrained to the Job/workflow being acted on rather than offering invalid phases from other workflows.

### Documents

- New workflow documents use `UploadDocumentAction`.
- Normal Job attachments use `UploadJobAttachmentAction`.
- Reusing a stored document as a workflow document uses the new `LinkStoredDocumentAction`, which feeds the existing `UploadDocumentAction` so required-document readiness/task-completion rules are preserved.
- Document deletion lifecycle was consolidated into `DeleteDocumentAction` so deletion from Livewire, API or other backend paths performs the same required-task reopening/readiness/workflow reconciliation.
- Stored-document selection is permission-scoped.

### Authorization

Server authorization is recalculated when actions run; permission decisions are not trusted merely because a value was populated during `mount()`.

Sensitive Client/financial fields, Job visibility, Task visibility, assignment changes, completed Task locks, document permissions, role matrix operations and Admin/Super Admin behavior continue to be enforced by the existing `FlowAuthorizationService` and backend actions.

## Loading/performance changes

The application no longer downloads the workspace into browser state after login.

### Page-level loading

Each Livewire route queries only what its screen requires. Navigation uses `wire:navigate`, so the Livewire runtime is reused instead of booting a separate JavaScript application per screen.

### Server pagination

Large operational collections are paginated instead of fully loaded:

- Jobs list;
- Clients list;
- My Work;
- Job Board / Task Board;
- Documents;
- Notifications;
- Workflow Setup;
- Task Pack Setup;
- Master Data;
- Client Job history;
- Job Tasks;
- Job Documents;
- Job transition history;
- Task Documents;
- Task activity history.

### Detail-page lazy querying

Job Details no longer eagerly loads Tasks, Documents, comments/history, workflow events and other large relations for every tab.

- Overview loads overview relations/counts.
- Workflow loads workflow history.
- Tasks loads the paginated Task slice and Task-only filter data.
- Commercial loads commercial data.
- Documents loads the paginated document slice and only opens the stored-document search when requested.
- History loads the paginated transition-event slice.

Task Details similarly paginates Documents and Activity with separate named paginators.

### Query-side filters

Job/Task repositories now support database-side filters for the active view instead of loading everything and filtering in browser memory. This includes search, workflow, Job, Client, effective assignee, status, priority, due-date windows, attention state and quick filters.

### Metrics

Operational metrics are calculated/cached per screen. Opening Dashboard does not also calculate Reports/Workflow Setup/Task Pack/Master Data/Administration metrics.

Dashboard/Reports workload aggregation uses the effective Task assignee, including Task Setup default assignees where no explicit override exists.

### Login asset split

The login page now loads:

- base CSS;
- login CSS;
- browser fallback CSS.

It does **not** load the full authenticated workspace stylesheet or Livewire runtime before authentication.

The authenticated workspace and login are separate Vite CSS entry points:

- `resources/css/flowtrack/login.css`
- `resources/css/flowtrack/app.css`

There is no custom JavaScript Vite entry.

## Board conversion

The Board is now server/Livewire-driven and preserves the original operational controls:

- Job / Task modes;
- workflow selector;
- New Job button;
- search;
- Job, Client, Assignee, Status, Due and Priority filters;
- Mine / Overdue / Due this week / Blocked / Waiting / Unassigned quick filters;
- expand/minimize behavior;
- Job phase changes with reason;
- Task status changes;
- Task due-date changes;
- Admin-authorized Task deletion.

Browser drag/drop is intentionally not a source of truth. Mutations use explicit Livewire controls so authorization and validation always run server-side. Existing Board CSS/classes are retained to preserve the supplied design and responsive behavior.

## Reports conversion

Reports is no longer rendered/exported by JavaScript. It includes:

- Quotation Conversion;
- On-time Jobs;
- Task Completion;
- Average Artwork Cycle;
- Shipment On Time;
- Open Receivables;
- Active Jobs by Phase;
- Team Workload;
- Delivery Performance;
- Receivable Ageing.

CSV export is generated by Laravel with `streamDownload`. `Print / PDF` opens a server-rendered printer-friendly view that can be saved as PDF using the browser's standard print facility.

## CSRF/session handling

CSRF protection was **not disabled**. Removing CSRF entirely from a cookie/session-authenticated Laravel application would weaken request security.

What was removed from the custom frontend architecture:

- custom `/flowtrack/csrf-token` recovery endpoint dependency;
- manual `X-CSRF-TOKEN` JavaScript handling;
- global `window.fetch` monkey patch;
- custom 419 retry loop.

What remains:

- native `@csrf` on Laravel login/logout forms;
- Laravel/Livewire's normal session/XSRF protection for Livewire mutations;
- normal authenticated middleware and workspace authorization.

This is the safer replacement for the fragile browser token-repair layer.

## Legacy compatibility API

`/flowtrack/template-api/*` remains as a **server-only backwards-compatibility API** because removing it could break existing integrations/tests or external consumers. The native Livewire UI has no dependency on it and does not call it.

Keeping those endpoints does not make the frontend hybrid: they are optional Laravel controller routes, not the renderer or state layer for the new UI.

They can be retired later after confirming no external integration consumes them.

## Browser compatibility

The custom application JavaScript dependency has been removed, which eliminates a major source of cross-browser failure. CSS includes compatibility fallbacks and the build targets Chrome/Chromium, Edge, Firefox and Safari families, including older still-managed versions configured in Vite.

IE11 is not a supported target for Laravel 13 + Livewire 4. Supporting IE11 would require a separate legacy frontend architecture and would conflict with the requested Livewire-first conversion.

## Verification completed in this artifact environment

Completed successfully:

- PHP syntax lint over 200 PHP files in `app`, `routes`, `config`, `database`, `tests` and build-check scripts;
- structural scan over 22 canonical Livewire/layout/login Blade files;
- no `wire:ignore` in canonical Livewire source;
- no custom application `<script>` delegation in canonical Livewire source;
- no inline application JS event handlers in canonical Livewire source;
- zero custom `.js` / `.mjs` application files under `resources/js` or `public`;
- zero custom CSRF/fetch-repair references in active application source;
- CSS entry import resolution check and generated two-entry Vite-compatible manifest verification;
- PHP syntax check for the new stored-document linking action and updated Reports component;
- shell syntax check for production/frontend build scripts;
- Livewire handler-to-public-method wiring check across all 20 page views;
- final Board grid uses the active status-column count rather than a hardcoded four-column layout.

Environment limitation: Composer dependencies (`vendor`) and `node_modules` were not available in this execution environment, so a full `php artisan test` / booted Laravel integration run and a real Vite build could not be executed here. A deterministic prebuilt CSS manifest/assets set is included in the delivered archive, and the production scripts require a successful `npm ci && npm run build` rather than silently continuing after a failed build.

## Deployment

Keep the production `.env`, database and storage. After replacing application files:

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
```

Recommended production runtime:

```env
APP_ENV=production
APP_DEBUG=false
FLOWTRACK_FORCE_HTTPS=true
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=redis
```

Do not run `migrate:fresh`, `db:wipe`, or demo seeders on the existing production database.
