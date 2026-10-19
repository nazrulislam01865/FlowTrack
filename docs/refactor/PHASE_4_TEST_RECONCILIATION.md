# Phase 4 test reconciliation

## Latest dependency-complete evidence

On 20 August 2026 the latest Mac run supplied with Archive 6 reported:

- PHPUnit: **45 failed, 229 passed (1,976 assertions)**.
- Vite 8 production build: **PASS**, 20 modules transformed, build completed in 718 ms.

This is a major improvement from the earlier Phase 4 runs (94 failures, then 88 failures). The remaining failures were reviewed against the executable Archive 6 source rather than treated as one undifferentiated regression set.

## Real application regressions corrected after the 45-failure run

### Create Order workflow availability

`WorkflowTemplate::availableForOrderCreation()` intentionally allows two categories:

1. normal Order workflows according to client availability; and
2. the exact selected client's **specific Inquiry workflow**.

Phase 4 had accidentally chained `where('applies_to', 'orders')` before that scope in create-order validation/render paths. That second restriction cancelled the scope's client-specific Inquiry branch and caused the three `CreateOrderWorkflowSelectionTest` failures.

The redundant narrowing has been removed from:

- `FilterOptionService` create-job workflow lookup;
- `Jobs/Index::preferredCreateOrderWorkflowId()`;
- `Jobs/Index::createOrderWorkflowAvailableForClient()`;
- final create-order validation; and
- create-page selected workflow hydration.

Generic Inquiry workflows remain excluded by the model scope.

### Shared Product create-on-no-match behavior

The shared product selector had made **Create Product** available independently of search results. FlowTrack's established behavior is stricter: inline product creation is offered only when a non-empty search returns zero matching products.

That rule now lives once in `components/catalog/create-product-quantity.blade.php`:

`$showCreateProductSuggestion = $productSearchValue !== '' && (int) $productResultTotal === 0;`

Both Inquiry and Order consume the same component and therefore the same behavior.

### Dashboard shipping metric compatibility

The dashboard shipping aggregate now recognizes both the current workflow phase and the persisted source workflow phase. This keeps reporting compatible with legacy/current workflow records without changing the metric's meaning.

## Test ownership / fixture corrections

The remaining source-contract failures were updated to follow the current component owners instead of restoring pre-refactor monolithic markup. Major examples:

- Board tests now validate source-managed module CSS instead of requiring an inline `<style>` block.
- Board lookup cache tests use the current `WorkflowTemplate` source of truth and v3 scalar cache keys.
- Client row avatar/initial tests follow `x-ui.client-logo` ownership.
- Client detail tests reflect the intentionally retired preview modal and current direct-detail behavior.
- Product search tests follow the shared `x-catalog.create-product-quantity` component.
- Product detail permission tests follow `x-catalog.detail-products-card` rather than requiring its heading in each parent view.
- Inquiry tests validate shared SearchSelect/product-card/My Work adapters and current automatic task-status helpers.
- My Work mention tests follow activity JSON mention metadata rather than the older notification-table implementation.
- Orders bulk-bar styling is validated in the route-scoped Orders module CSS rather than page markup.
- Document archive, Dashboard, sidebar, lazy-loading, and terminology assertions were aligned with their current source owners.
- Tests that queried an empty in-memory SQLite database without migrations now use `RefreshDatabase` and create their own fixtures.

## Regression gates added/strengthened

`quality:phase4` now also protects the two application rules above:

- a caller may not re-narrow `availableForOrderCreation()` with `applies_to=orders`;
- the shared product selector must guard Create Product behind non-empty zero-match search state plus permission.

The existing fixture/test-contract gate continues to protect FlowTrack's UserFactory defaults and unsafe source-assertion interpolation.

## Validation in this package

The corrected source passes:

- PHP lint: **385 files**;
- Phase 0 architecture budget;
- Phase 1 CSS foundation gate;
- Phase 2 component-library gate;
- Phase 3 migration gate;
- Phase 4 shared filter/search gate;
- test/fixture contract gate.

The package intentionally excludes `vendor/` and `node_modules/`, so the full PHPUnit and Vite build must be rerun in the dependency-complete Mac environment. The latest supplied Vite build already passed; the supplied 45-failure PHPUnit result predates the corrections documented above.
