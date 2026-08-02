# FlowTrack Performance / Lazy Loading Update

Date: 2026-08-02

## Goal

Reduce login and navigation time by avoiding full-workspace hydration, load only the records/configuration required by the active screen, keep detail code lazy, and remove the custom CSRF refresh/retry layer without weakening request-forgery protection.

## What changed

### 1. Small login/bootstrap payload

`GET /flowtrack/template-api/bootstrap` now returns only the core session/runtime state:

- current user
- role/capability map
- board visibility mode
- up to 20 notifications
- paginated list endpoint URLs
- page-context endpoint URL

It intentionally returns empty operational collections for Jobs, Tasks, Clients and Documents. It no longer loads workflows, Task Packs, master data, access-control matrices, reports or workspace-wide operational rows during login.

### 2. Page-specific context

New endpoint:

`GET /flowtrack/template-api/context/{page}`

The endpoint loads only configuration required by the requested screen. Examples:

- Dashboard: dashboard metrics + 6 recent task activities
- Jobs: assignees, client lookup labels, workflow/phase configuration and master-data needed by the Job UI
- Board: assignees, workflow/master configuration and board-visible Job IDs
- Reports: report metrics only
- Task Pack Setup: Task Packs/workflow/master configuration + Task Pack metrics
- Administration: users/access-control/master configuration + administration metrics

### 3. Bounded list loading

Operational rows use the existing segmented endpoints, now with a lower safe default page size of 25 (maximum 50):

- `/flowtrack/template-api/lists/clients`
- `/flowtrack/template-api/lists/jobs`
- `/flowtrack/template-api/lists/tasks`
- `/flowtrack/template-api/lists/documents`

Initial screen profiles are bounded:

- Dashboard: 12 Jobs + 12 Tasks
- Jobs: 40 Jobs
- Clients: 40 Clients
- Operations Board: 50 Jobs + 50 Tasks
- My Work: 50 Tasks
- Documents: 40 Documents

Additional rows are fetched only through Next / Load More / board scrolling.

### 4. Page-scoped metrics cache

Previously requesting metrics calculated Dashboard, My Work, Reports, Workflow, Task Pack, Master Data and Administration aggregates together.

`OperationalMetricsService::forPage()` now calculates only the metric group required by the active page. `FlowTrackMetricsCache::rememberSection()` caches each section independently for the configured metrics-cache lifetime.

### 5. Lazy JavaScript detail modules

The Jobs list no longer needs to preload the entire Job-detail stack. The list loads its configuration layer first. Job detail/edit modules are requested when the user:

- opens a Job
- opens the Job action menu
- edits Job owner/delivery inline
- creates a Job

Generated modules remain split under `public/flowtrack/modules/`.

### 6. CSRF / request security cleanup

Removed:

- custom `/flowtrack/csrf-token` route
- `CsrfTokenController`
- CSRF meta-tag dependency
- `FLOWTRACK_CSRF_AUTO_RECOVERY`
- global `window.fetch` replacement/retry behavior
- manual `X-CSRF-TOKEN` header handling
- CSRF token copied into Pusher runtime configuration

Kept (intentionally):

- Laravel request-forgery middleware
- `@csrf` on the native login form
- Laravel's standard `XSRF-TOKEN` cookie
- `X-XSRF-TOKEN` on state-changing AJAX/Pusher authorization requests

This avoids disabling a security boundary simply to hide 419 responses. A genuinely expired secure session now returns the user to login instead of silently retrying a stale request.

### 7. Browser compatibility

Added `flowtrack-browser-compatibility.js` before the main app scripts with fallbacks for APIs used by FlowTrack, including:

- `String.prototype.replaceAll`
- `queueMicrotask`
- `Object.fromEntries`
- `NodeList.prototype.forEach`
- `Element.matches` / `Element.closest`
- `CustomEvent`

Added CSS fallbacks for dynamic viewport units and progressive `:has()` use. Vite CSS target is configured for Chrome 80+, Edge 80+, Firefox 78+ and Safari 13.1+ while current Chrome/Edge/Firefox/Safari remain the primary target.

IE11 is not a supported target for this build; supporting it correctly would require a separate legacy JavaScript/CSS build and removal/polyfilling of additional platform APIs.

### 8. Build verification

`scripts/verify-assets.mjs` now validates:

- lazy page-context/data markers
- compatibility assets
- standard XSRF request handling
- absence of the removed custom CSRF recovery layer
- the new realtime page-context refresh flow

## Production settings recommended

For the cloud deployment, use Redis for shared session/cache state and queues if Redis is available:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://YOUR_HOST

SESSION_DRIVER=redis
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax

CACHE_STORE=redis
QUEUE_CONNECTION=redis

FLOWTRACK_LIST_PER_PAGE=25
FLOWTRACK_METRICS_CACHE_SECONDS=120
```

Keep `APP_URL` and the reverse-proxy HTTPS headers correct. Do not serve the authenticated application partly over HTTP and partly over HTTPS.

## Deployment commands

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build

php artisan optimize:clear
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

No new database migration was added by this optimization pass; the project already contains the FlowTrack performance-index migration.

## Validation performed on this package

Passed:

- PHP syntax checks for all modified PHP files
- JavaScript syntax checks for the source, loader and all generated lazy modules
- generated lazy module rebuild
- `node scripts/verify-assets.mjs`
- search proving the removed custom CSRF endpoint/recovery/header code is absent
- route presence for page context and paginated list endpoints

Not executed in the analysis environment:

- Laravel/Pest runtime test suite because the uploaded archive does not contain `vendor/`
- a fresh Vite dependency install because the analysis environment's npm proxy returned a 404 for the Vite tarball

The committed/generated frontend assets were regenerated directly from the source splitter, and the built CSS manifest was updated with the compatibility CSS so this ZIP includes deployable public assets as well as source files.
