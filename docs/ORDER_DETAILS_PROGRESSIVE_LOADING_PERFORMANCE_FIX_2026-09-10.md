# Order Details progressive-loading performance fix — 2026-09-10

## Evidence used

Production measurements for the same Order Details flow showed:

- Initial `jobs.index` GET: ~7.9–15.6 s, typically ~320–336 queries.
- Livewire `default-livewire.update`: ~10.9–17.0 s.
- A 13.1 s Livewire request spent only 64.17 ms in SQL, proving the dominant remaining cost was outside MySQL.
- Chrome DevTools showed one Livewire update containing four calls in a single payload:
  - `loadDetailSection('activity', 'order', 344)`
  - `loadDetailSection('products', 'order', 344)`
  - `loadDetailSection('attachments', 'order', 344)`
  - `loadDetailSection('workflow', 'order', 344)`

The compact skeleton placeholders plus large IntersectionObserver margins caused all four heavy sections to become eligible together. Livewire then bundled them into one expensive update.

## Changes

### 1. Serialize heavy Order Details viewport loads

`resources/views/components/ui/progressive-section-loader.blade.php`

- Adds an opt-in, group-scoped browser queue.
- Only one eligible section in a queue group is sent at a time.
- Waits for the Livewire request to complete before considering the next section.
- Re-checks viewport eligibility after Livewire morph/layout changes.
- Removes queued placeholders that have moved outside the prefetch area.
- Existing progressive loaders that do not set `queueGroup` keep their old behavior.

### 2. Order Details uses one queue and smaller prefetch windows

`resources/views/components/jobs/detail-overview.blade.php`

Order detail sections now use the same `order-detail-{id}` queue and DOM-order priorities:

1. Products — priority 10, root margin 160px
2. Workflow — priority 20, root margin 120px
3. Attachments — priority 30, root margin 80px
4. Activity — priority 40, root margin 40px

This prevents the original four-call Livewire payload. If Products expands and pushes lower placeholders away from the viewport, the lower sections stay unloaded until the user actually approaches them.

### 3. Avoid redundant section-ready state mutations

`app/Livewire/Jobs/Concerns/ManagesDetailProgressiveLoading.php`

Repeated calls for an already-ready section return immediately. No business behavior is changed and no valid section request is silently dropped.

### 4. Skip full workflow repair when runtime already matches setup

`app/Services/OrderWorkflowBindingService.php`

A strict read-only verification path proves whether the active Order runtime already matches the published workflow/task-pack definition. Only a proven match skips the existing repair transaction; any mismatch falls back to the original full synchronization.

### 5. Avoid unconditional runtime-mirror phase writes

`app/Services/OrderWorkflowSetupService.php`

Workflow phase rows are updated only when `workflow_id` is null or incorrect, instead of issuing the same write every Order open.

### 6. Cheap no-op path for Artwork evidence repair

`app/Services/OrderArtworkEvidenceService.php`

The service now uses bounded `EXISTS` checks first. When there are no detached Order documents or links from retired tasks, it returns without hydrating the complete repair graph. If detached evidence exists, the original repair logic runs unchanged.

### 7. Auto-advance no longer hydrates the complete Order detail graph

`app/Services/LegacyJobService.php`

`maybeAutoAdvance()` previously called `findVisible()`, which loads the large legacy Order graph even though blocker evaluation only needs current-phase workflow/task/document evidence.

It now uses a purpose-built read model:

- lightweight Order shell;
- published phase metadata;
- current-phase tasks;
- current-phase Task Pack requirements;
- current-task documents and links.

If the phase is actually ready, `completePhase()` still uses the original mutation path and full validation, so advancement semantics remain unchanged.

## Validation

All changed PHP/Blade files pass `php -l` in the supplied project archive. A source-level regression test was added:

`tests/Feature/OrderDetailProgressiveLoadingPerformanceTest.php`

Run locally with project dependencies installed:

```bash
php artisan optimize:clear
php artisan test --filter=OrderDetailProgressiveLoadingPerformanceTest
```

Then verify in Chrome DevTools that each Order Details progressive `update` payload contains only one `loadDetailSection(...)` call at a time.

## Benchmark status

No post-change wall-clock numbers are claimed in this document because the supplied archive does not contain `vendor/`, so the Laravel application cannot be booted in the analysis environment. Compare the same Order on the target runtime using the existing request-performance monitor.
