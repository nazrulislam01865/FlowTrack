# Order Details full isolation performance pass 6 — 2026-09-10

This pass applies the complete Order Details isolation optimization to the latest Archive 5(4) base.

## Implemented

- Lightweight initial Order Details shell.
- Products remains isolated in its own Livewire component.
- Workflow is isolated in its own Livewire component and retains the existing task/workflow/shipment/document concerns.
- General attachments are isolated in their own Livewire component.
- Activity is isolated and paginated at 10 rows per page.
- All four heavy sections share the same progressive queue and load one at a time when near the viewport.
- Initial Order Details opening no longer performs workflow reconciliation, artwork repair, or safe auto-advance; those run when the Workflow child is reached.
- Initial overview uses the lightweight summary read model and lightweight Redo summary context.
- Mention users are no longer loaded during the initial Order Details render.

## Acceptance target

The requested first usable Order Details target is 4–5 seconds and no more than 7 seconds under normal load. This code removes the measured parent Livewire bottlenecks, but the target must be verified on the same production/staging server and a comparable large Order.

## Validation commands

```bash
php artisan optimize:clear
php artisan test --filter=OrderDetailsFullIsolationPerformanceTest
php artisan test --filter=OrderDetailProgressiveLoadingPerformanceTest
php artisan test --filter=OrderProductsSectionIsolationPerformanceTest
php artisan test --filter=OrderWorkflowModalPreviewPerformanceTest
```
