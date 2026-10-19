# FlowTrack Livewire Redesign

This package keeps the previous database schema/migrations and redesigns the Jobs list, Task Details, and Job Details screens to match the supplied references.

Updated areas:
- Jobs list page with reference-style filter bar, quick chips, owner/date/progress/invoice cells, and pagination.
- Task Details page with top action bar, property grid, checklist, attachment upload area, activity panel, management attention, job context, and dependencies.
- Job Details overview with header/tabs, metric cards, overview/product table, workflow strip, phase task table, planning panel, and activity.
- Job Details workflow section with blocker banner, stepper, transition readiness, phase controls, workflow details, and phase history.
- Job Details documents section with upload area, grouped document table, required document sidebar, and document health panel.

Database schema note:
- A performance-only migration adds indexes for high-frequency Job, Task, notification, activity, document, Master Data, and workflow queries.
- It does not change application records or remove existing columns.

Run after replacing files:

```bash
php artisan optimize:clear
npm install
npm run build
php artisan serve
```

If you already ran `npm install`, only run:

```bash
php artisan optimize:clear
npm run build
```

## Production performance deployment

The cloud performance implementation, Redis/Reverb configuration, queue worker setup, monitoring, and query-plan verification steps are documented in [`PERFORMANCE_IMPLEMENTATION.md`](PERFORMANCE_IMPLEMENTATION.md).

For production deployment, begin with `.env.production.example` and run:

```bash
./scripts/deploy.sh
./scripts/queue-worker.sh
```

After upgrading from a legacy FlowTrack schema, run the compatibility sync once:

```bash
php artisan flowtrack:sync-legacy
```

Legacy synchronization no longer runs during normal page requests. China-specific deployment notes and diagnostics are in [`CHINA_PERFORMANCE_UPDATE.md`](CHINA_PERFORMANCE_UPDATE.md).

## Inquiry Status / Taskflow update

This build adds **Inquiry Statuses** to Master Data. The Inquiry list and Overview status editor now use the active values from that Master Data type. Run migrations before using the updated Inquiry screen:

```bash
php artisan migrate --force
php artisan optimize:clear
```

The Inquiry Details UI now calls the task sequence **Taskflow**. The configured Workflow Setup remains the source used when creating an Inquiry; only the Inquiry detail-facing terminology changed.

## Order Details document UX update — 2026-08-09

The Order Details → Documents tab has been reorganized for faster daily use without changing the document data model or upload/link behavior:

- compact document summary and required-document progress;
- a two-step upload/link area that clearly separates requirement selection from file selection;
- workflow phases shown as collapsible groups instead of a wide document table;
- each requirement shows its task, missing/received state, linked files, and direct action in one card;
- task attachments are visually separated from required Task Pack documents;
- responsive layouts for desktop, tablet, mobile, and narrow mobile screens.

No migration is required for this UI update.

### 2026-08-09 — Order detail centering refinement
- Mobile workflow phase stepper is centered when the configured phases fit within the viewport; longer workflows remain horizontally scrollable.
- Mobile Task Pack document requirement cards center the requirement content/action group and give the Upload button a balanced full-width target inside that centered group.
- Desktop layouts are unchanged.

## Dashboard + All Tasks alignment update — 2026-08-09

Mobile/tablet alignment was refined for the Dashboard secondary sections and the All Tasks task list. Ongoing jobs/tasks now use deliberate responsive card grids, Dashboard headings/actions wrap safely, and All Tasks uses a compact tablet layout plus full-width status controls on phones. No database or workflow behavior changed.

## 2026-08-09 session stability update

The project now normalizes session-cookie transport settings per request, prevents caching of dynamic CSRF-bearing HTML, gracefully recovers from stale CSRF sessions, and preloads the scoped Inquiry stylesheet in the authenticated shell to remove the first-navigation design flash. See `SESSION_419_AND_INQUIRY_FLASH_FIX.md`.

## 2026-08-09 My Task loading regression fix

The My Task page no longer starts a second automatic Livewire metrics request after first paint, and its "Updating tasks..." indicator is now hidden unless a real list request is in progress. See `MY_WORK_INFINITE_LOADING_FIX.md` for the root cause and deployment notes.

## 2026-08-10 — Direct screenshot capture in rich text

Rich-text fields now include a **Capture** action beside the existing image insert action. On supported desktop browsers, clicking it opens the browser's native screen-sharing picker so the user can choose a screen, application window, or browser tab. FlowTrack captures one frame, immediately stops the display-sharing stream, uploads the screenshot through the existing protected rich-text image endpoint, and inserts it at the current editor cursor position.

Saved rich-text screenshots keep the image preview controls, including **Open in new window**, zoom, download, and close.

Notes:
- Screen capture requires HTTPS in production (localhost is allowed by browsers for development).
- The exact screen/window/tab choices are controlled by the browser/operating system.
- Browsers without `getDisplayMedia()` show a clear unsupported-browser message; normal paste/image upload continues to work.
- No database migration is required.

## 2026-08-12 — Laravel Reverb realtime migration

Pusher Cloud has been replaced by self-hosted Laravel Reverb for FlowTrack realtime notifications and workspace refresh signals. Database notifications and the polling fallback are unchanged. Local and Alibaba Cloud setup instructions are in [`REVERB_IMPLEMENTATION.md`](REVERB_IMPLEMENTATION.md).

## 2026-08-12 — Create Order product search correction

The Create Order **Products & quantities** prototype now renders only real Product master records in its search results. Product Category remains metadata/filter information only. Legacy product descriptions that started with the category name (for example `Caps · Embroidery`) are normalized only for display so the result card shows the product detail (`Embroidery`) instead of repeating the category. Product queries now also load the `type` field required by the existing product-image helper, restoring catalogue images in search results, selected-product rows, and duplicate-product cards without changing stored data or the supplied UI design.

No database migration is required for this correction.

## 2026-08-12 — Create Order product master source fix

The Create Order **Products & quantities** search now reads from the exact same `product` Master Data query used by the Products page. Product Categories are loaded separately and are used only as filter/display metadata, so a category record can never be emitted as a product result. The category filter now uses the category record ID internally while preserving the supplied UI design, and selecting a result always stores the Product master record ID/name in the Order item.

No database migration is required for this fix. After deployment run:

```bash
php artisan optimize:clear
```

## Enterprise refactor status (Phase 15)

The executable source is at Phase 15 of the FlowTrack enterprise refactor. Run `npm run quality:phase15` for the complete architecture chain. Production release additionally requires a clean `composer install`/`npm ci`, Pint, PHPUnit, Vite build + bundle budget, dependency audits, and approved browser/visual/load checks as documented in `docs/ci-cd.md` and `docs/ENTERPRISE_READINESS_REPORT.md`.

`public/build` is generated output and must be rebuilt from this revision; do not reuse an older Vite manifest.
