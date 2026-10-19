# FlowTrack Performance Stage 3 + 4 — 2026-09-10

## Evidence used

The local cold-browser trace after Stage 2 showed three `/notifications/unread-count` requests during one Order Details navigation. Two were initiated by the Vite application bundle and one by `public/js/flowtrack-sidebar-navigation.js`. The matching Laravel logs showed each normal request performing 8 database queries, including 5 repeated database-cache lookups.

The same trace/log set also showed repeated 404 requests for protected stored assets, including branding assets, client logos, profile images and product images. Those requests boot Laravel and, for some asset types, execute authorization/model queries before returning 404.

## Stage 3 — one persisted-counter request source

Added `resources/js/core/persisted-counters.js` as the single request owner for `/notifications/unread-count`.

The shared request layer:

- coalesces concurrent notification/workspace requests into one Promise;
- reuses the latest payload for a 1.5 second lifecycle burst;
- updates Notification, My Work and Cancelled Order sidebar counters from the same payload;
- preserves the existing 30/60 second workspace polling cadence;
- preserves the existing 60 second notification fallback cadence;
- preserves notification latest-id detection and Livewire refresh dispatch;
- preserves workspace `data_version` change detection.

`public/js/flowtrack-sidebar-navigation.js` no longer performs its own counter fetch. It only handles navigation-state presentation.

A Livewire navigation explicitly asks the workspace state consumer to sync, so persisted sidebar counters stay current even though the classic sidebar script no longer owns an HTTP request.

### Expected measurement

Before: 3 initial `/notifications/unread-count` calls in the supplied trace.

Target after rebuild: 1 call during the same initial navigation burst. A later call after the cooldown/poll/reconnect interval remains valid behavior.

## Stage 4 — suppress routes for missing protected assets

Added request-scoped `App\Services\StoredAssetUrlService`.

The service verifies protected public-disk files before emitting application asset routes for:

- profile images;
- client logos;
- product images;
- workspace logo/favicon.

Existence checks are memoized per request so the same stored path is not checked repeatedly within one render. Existing files keep the same protected route URLs and controller authorization behavior. Missing files now produce `null`, allowing existing initials/icon/placeholder UI to render without a guaranteed 404 request.

No database values are deleted or rewritten. If a file is temporarily unavailable or later restored, the stored path remains intact.

Direct profile-image route generation in legacy/order/inquiry UI code was moved through the same guarded URL path so it cannot bypass this check.

## Files changed

- `app/Livewire/UserEditor/Index.php`
- `app/Models/Client.php`
- `app/Models/MasterRecord.php`
- `app/Models/User.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/BoardTaskPackService.php`
- `app/Services/BrandingService.php`
- `app/Services/LegacyInquiryService.php`
- `app/Services/MyWorkService.php`
- `app/Services/StoredAssetUrlService.php` (new)
- `public/js/flowtrack-sidebar-navigation.js`
- `resources/js/app.js`
- `resources/js/core/persisted-counters.js` (new)
- `resources/js/features/notifications.js`
- `resources/js/features/workspace-refresh.js`
- `resources/views/components/jobs/table.blade.php`
- `resources/views/components/ui/avatar.blade.php`
- `tests/Feature/StoredAssetUrlGuardPerformanceTest.php` (new)
- `tests/JavaScript/persisted-counters-shared-request.mjs` (new)

## Validation performed in the supplied archive

Passed:

- PHP syntax checks for all changed PHP files.
- Node syntax checks for all changed JavaScript files.
- `npm run test:js:syntax`.
- `npm run test:js:unit`.
- `node tests/JavaScript/notification-sync-dedupe.mjs`.
- `node tests/JavaScript/persisted-counters-shared-request.mjs`.

Could not execute:

- `npm run build`: the uploaded archive contains no `node_modules`, so the Vite executable is unavailable in this environment.
- Laravel PHPUnit: the uploaded archive contains no `vendor` directory.

Existing quality-suite failures were reproduced unchanged on the original supplied archive and are unrelated to Stage 3/4.

## Local verification

After replacing files:

```bash
npm run build
php artisan optimize:clear
php artisan test --filter=StoredAssetUrlGuardPerformanceTest
```

Then keep DevTools open with Disable cache enabled and hard reload the same Order Details page.

Expected Stage 3 result: one initial `/notifications/unread-count` request rather than three.

Expected Stage 4 result: stale protected image paths should render existing fallbacks without requests to `branding-assets.show`, `client-logos.show`, `profile-images.show`, or `master-data.product-image` for files that do not exist locally.

Use:

```bash
tail -f storage/logs/laravel.log
```

to verify the request counts and confirm no workflow/task behavior changed.
