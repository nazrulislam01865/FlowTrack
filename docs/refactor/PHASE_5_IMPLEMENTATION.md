# Phase 5 implementation — Orders decomposition

Date: 2026-08-22
Source: `Archive 2 (2026-08-22 10:08:56)`

## Scope

Phase 5 decomposes the Orders UI/Livewire boundary without changing Order business semantics, routes, deep links, database schema, workflow rules, CSS design, or the existing service implementations.

The implementation follows the roadmap's compatibility-first rule: `App\Livewire\Jobs\Index` remains the public Livewire/URL state owner while focused Order concerns, Actions and Queries own the extracted implementation.

## Preserved compatibility contract

The following were deliberately preserved:

- `/orders` routing and `jobs.index` route behavior.
- `resources/views/pages/jobs.blade.php` switching between the normal Orders list and the compatibility detail/create/task component.
- URL parameters `open`, `task`, `comment`, and `create`.
- All 186 pre-Phase-5 public `Jobs\Index` method names used by Blade/Livewire bindings.
- Existing validation keys, modal state, pagination state and realtime refresh behavior.
- Existing `JobService`, `TaskService`, `DocumentService`, and `OrderFinanceService` business behavior.
- Existing workflow progression, audit activity, notifications and transaction behavior delegated to those services.
- Existing Order markup and CSS output for the Blade sections split in this phase.

No database migration was introduced.

## Livewire structure

`app/Livewire/Jobs/Index.php` is now a compatibility coordinator and state container. Workflow implementation is composed from:

- `ManagesOrderCreation`
- `ManagesCreateOrderProducts`
- `ManagesOrderNavigation`
- `ManagesOrderFinance`
- `ManagesOrderInquiryLink`
- `ManagesOrderWorkflow`
- `ManagesOrderDetail`
- `ManagesOrderProducts`
- `ManagesOrderTasks`
- `ManagesOrderDocuments`
- `ManagesOrderTaskResources`
- `ManagesOrderActivity`
- `BuildsOrderPageData`

The coordinator decreased from 4,721 lines to 315 lines while retaining the same public Livewire method surface.

The normal Orders list remains the focused `App\Livewire\Orders\Index` component. It now delegates its list read model to `OrderListQuery` and deletion commands to focused Actions.

## Order Actions

Focused transport-independent Actions were introduced under `app/Actions/Orders` for touched write paths:

- `CreateOrder`
- `UpdateOrderOwner`
- `UpdateOrderCoordinator`
- `UpdateOrderDeliveryDate`
- `UpdateOrderPriority`
- `UpdateOrderHealth`
- `UpdateOrderShippingDetails`
- `UpdateOrderOverview`
- `UpdateOrderTextField`
- `SetOrderAttention`
- `ClearOrderAttention`
- `CancelOrder`
- `AddOrderComment`
- `DeleteOrderTask`
- `EmailOrderInvoice`
- `LinkOrderInquiry`
- `UnlinkOrderInquiry`
- `DeleteOrder`
- `DeleteOrders`

These Actions do not depend on Livewire. They delegate to the existing proven services so Phase 5 does not duplicate or rewrite domain rules.

## Order Queries

Permission-scoped read boundaries were introduced under `app/Queries/Orders`:

- `VisibleOrderQuery` — visible Order base/detail/tab/activity/inquiry-link reads.
- `OrderListQuery` — operational Orders list, stages, rows and visible-selection reads.

Phase 5 intentionally uses the existing service scopes behind these Queries. Deeper service decomposition belongs to Phase 8.

## Blade decomposition

Stable Blade sections were extracted with `@include` so the parent Livewire state contract is inherited directly and no new child-component synchronization contract is introduced.

| Parent view | Before | After | Extracted area |
| --- | ---: | ---: | --- |
| `jobs/create-products.blade.php` | 405 | 54 | missing-supplier and create-product modals |
| `jobs/detail-documents.blade.php` | 507 | 80 | required-document uploader and document library |
| `jobs/task-detail.blade.php` | 348 | 110 | properties, description, checklist, attachments, activity, sidebar |
| `orders/prototype-list.blade.php` | 321 | 70 | header/stages, filters, table and pagination |

The Phase 5 gate reconstructs each parent by expanding its partials and verifies the SHA-256 against the untouched pre-Phase-5 source. All four reconstructions match exactly.

No new child Livewire component was forced where state ownership remains tightly shared. This follows the roadmap rule to extract a child only when its state boundary is clear.

## Regression/source tests

Existing source-contract tests that previously assumed every Order implementation detail lived in `Jobs/Index.php` now read the composed Phase 5 Order source through `Tests\Support\OrderPhase5Source`. The assertions themselves remain unchanged in meaning.

`OrderPhase5ArchitectureTest` protects the coordinator size, deep-link contract and Livewire-independent Action/Query boundary.

## Quality gate

Run:

```bash
npm run quality:phase5
```

This executes the complete Phase 0–4 chain first and then the Phase 5 Orders gate.

Phase 5 verifies:

- `Jobs/Index.php` remains at or below 500 lines.
- all required Order concerns exist and are composed by the coordinator.
- all 186 original public Livewire methods remain available.
- required Order Actions/Queries exist and do not depend on Livewire.
- protected routes, route-selection view and core Order services have not changed.
- `open`, `task` and `comment` deep-link attributes remain present.
- extracted Blade views reconstruct exactly to their pre-Phase-5 source.

## Validation performed in this archive

- `npm run quality:phase5`: PASS.
- Phase 0 architecture budget: PASS with previously documented inherited exceptions only.
- Phase 1 CSS foundation: PASS.
- Phase 2 UI component library: PASS.
- Phase 3 CSS migration: PASS.
- Phase 4 shared forms/filter/search: PASS.
- Phase 5 Orders decomposition: PASS.
- PHP syntax check: 456 PHP files passed.
- Protected `JobService`, `TaskService`, `DocumentService`, `OrderFinanceService`, route file and Jobs route-selection views match their pre-Phase-5 hashes.

The supplied archive does not contain `vendor/` or `node_modules/`. Therefore the full PHPUnit runtime suite, Pint and production Vite build cannot be executed from this archive alone. They remain mandatory before deployment in the normal dependency-complete environment.

## Explicitly not part of Phase 5

- Inquiry decomposition (Phase 6).
- Master Data/Client/setup decomposition (Phase 7).
- Broad `JobService` decomposition or DTO program (Phase 8).
- Authorization/tenancy hardening (Phase 9).
- Upload storage/security redesign (Phase 10).
- General query/index optimization (Phase 11).
- UI redesign or workflow behavior changes.
