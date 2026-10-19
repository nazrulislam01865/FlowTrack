# Order Details full isolation performance pass 5 — 2026-09-10

## Evidence that justified this pass

Production DevTools showed the Order document taking about 6.4–7 seconds and the remaining parent Livewire section requests taking about 6.3–7.6 seconds. Products had already been isolated and its corresponding request was observed at about 160 ms, which established that parent `Jobs\\Index` hydration/rendering was the dominant cost for the heavy sections.

## Changes

### Initial Order Details request

The `Jobs\\Index` detail branch now prepares a read-only shell. The initial request no longer performs workflow reconciliation, artwork repair or auto-advance. Those operations run in the isolated Workflow component before workflow actions become available.

The overview shell now uses `VisibleOrderQuery::loadOverviewShell()`, `OrderDetailViewService::buildSummary()` and `OrderRedoService::summaryContext()` rather than the full workflow/detail/redo graphs.

### Isolated lower sections

Order Overview now mounts four independent Livewire components:

1. `OrderProductsSection` — priority 10
2. `OrderWorkflowSection` — priority 20
3. `OrderAttachmentsSection` — priority 30
4. `OrderActivitySection` — priority 40

All use the same progressive queue group, so only one heavy section request runs at a time and later sections are requested only when still close enough to the viewport.

### Workflow safety

The Workflow child reuses the existing workflow/task/shipment/document concerns. Before it exposes task actions it runs the existing workflow binding reconciliation and auto-advance rules. If reconciliation or auto-advance changes runtime stage state it notifies the lightweight parent shell to refresh.

### Attachments

General Order attachments now load and mutate inside their own child. Upload/delete operations use the permission-scoped base order read rather than hydrating the full Order detail graph.

### Activity

Activity now has its own child and loads 10 rows per page. Mention users are prepared only when the user is permitted to comment in that activity component.

## Behavior intentionally unchanged

- Order Details visual design and typography
- Task Pack required/optional rules
- workflow task actions and phase advancement
- claim/assignment authorization
- artwork/document evidence behavior
- shipment actions and modals
- hold/cancel/attention behavior
- Redo and Finance tabs
- existing product actions

## Validation

All modified PHP and Blade files pass `php -l` syntax validation. Structural regression checks verify that the four heavy sections are independent Livewire components, share one progressive queue, and the initial `prepareSelectedJob()` path does not run workflow maintenance.

The uploaded archive does not include `vendor/`, so the Laravel/PHPUnit suite cannot be booted in this environment. Run the commands below in the real project before deployment.

```bash
php artisan optimize:clear
php artisan test --filter=OrderDetailsFullIsolationPerformanceTest
php artisan test --filter=OrderDetailProgressiveLoadingPerformanceTest
php artisan test --filter=OrderProductsSectionIsolationPerformanceTest
php artisan test --filter=OrderWorkflowModalPreviewPerformanceTest
```

## Performance acceptance

The production target requested for first usable Order Details paint is 4–5 seconds and never above 7 seconds under normal load. This code removes the measured architectural bottlenecks, but the target must be verified on the same production/staging server and same large Order. Do not claim the target until new `jobs.index` and isolated child timings are recorded.
