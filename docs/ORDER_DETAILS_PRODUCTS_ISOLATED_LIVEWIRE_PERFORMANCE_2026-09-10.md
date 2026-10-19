# Order Details Products isolated Livewire performance pass — 2026-09-10

## Evidence

Production Chrome measurements showed that, after the progressive queue fix, the Order Details `products` call was correctly isolated as its own `loadDetailSection("products", ...)` call but still took about 15 seconds and returned only about 17 KB. Previous server logs also showed large Livewire wall time with comparatively small SQL time. This supports isolating the Products render from the large `Jobs\\Index` component rather than adding database indexes or changing product UI.

## Change

`Order Details > Products` is now hosted by `App\\Livewire\\Jobs\\OrderProductsSection`.

The child component:

- starts as a lightweight placeholder;
- joins the existing `order-detail-{id}` progressive queue at priority 10;
- requests product data only when the Products section approaches the viewport;
- loads only the visible Order base plus the existing `loadOverviewProducts()` relation graph;
- reuses the existing `x-jobs.order-detail.products` Blade component without visual redesign;
- reuses the existing `ManagesOrderProducts` action concern;
- preserves add/edit/remove/restore and missing-supplier authorization behavior;
- keeps product interactions inside the child Livewire component so they do not hydrate/rerender the whole `Jobs\\Index` coordinator.

Workflow, Attachments and Activity remain on the existing parent progressive path for this pass. They should be extracted only after Products is benchmarked, matching the incremental safety plan.

## Expected network behavior

Before this pass the Products request payload was owned by the large Jobs component and looked like:

```text
loadDetailSection("products", "order", <id>)
```

After this pass the Products placeholder calls `loadProductsSection()` on the nested Products component. The request remains serialized with Workflow/Attachments/Activity through the existing browser queue, but only the Products child is hydrated and rendered.

## Validation

Run:

```bash
php artisan optimize:clear
php artisan test --filter=OrderProductsSectionIsolationPerformanceTest
php artisan test --filter=OrderDetailProgressiveLoadingPerformanceTest
```

Then open the same production-like Order used for the previous benchmark and inspect Chrome Network. Compare the Products update request against the previous ~15.03 second baseline.

Do not proceed to Workflow extraction until the Products after-measurement is captured.

## Structural before/after

The current `Jobs\\Index` class declares 202 public properties in its own file. The isolated Products component plus its reusable missing-supplier trait expose 42 explicit public properties. Therefore a Products-only update no longer targets the coordinator that owns the much larger Order/Create/Finance/Workflow/Shipment state surface.

This is a structural measurement only; the production wall-clock after value must be measured on the same Order after deployment.
