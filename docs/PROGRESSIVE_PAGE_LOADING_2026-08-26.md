# Progressive page loading standard — 2026-08-26

## Goal

Keep the authenticated FlowTrack shell responsive while data-heavy page content is loaded progressively. The sidebar/topbar must remain immediately usable and must not participate in page-content lazy loading.

## Livewire 4 loading rule

FlowTrack now uses two loading modes deliberately:

- **`defer` for page-level Livewire components** because they are always visible. The application shell renders first, then the page component hydrates after the initial response.
- **`lazy` for independent below-the-fold components** that should not query the server until they enter the viewport. Dashboard mentions now use this mode.

Using `lazy` blindly on an always-visible page root would still trigger almost immediately, so `defer` is the correct Livewire 4 primitive for page roots.

## Central placeholder

`config/livewire.php` defines:

```php
'component_placeholder' => 'livewire.shared.page-placeholder',
```

The placeholder view is:

`resources/views/livewire/shared/page-placeholder.blade.php`

It is query-free and uses the existing FlowTrack progressive skeleton components. Components with their own `placeholder()` method may override this while preserving the same rule: placeholders must never perform database work.

## Page coverage

All page Blade wrappers containing a page-level Livewire component now use `defer`, including Dashboard, Orders, Order detail/create, Inquiries, Clients, My Task, All Tasks, Documents, Notifications, Reports, cancelled orders, setup pages, profile/user editor and administration pages.

`resources/views/pages/bulk-order-import.blade.php` is intentionally excluded because it is not a page-level Livewire component and does not load large database collections on initial render. Its expensive work already starts only after the user supplies a file.

## Existing deeper progressive loading retained

The page-level standard does not replace deeper data-level loading that already reduces unnecessary queries:

- Product list: records are loaded after the shell with `wire:init="loadMasterRecords"`.
- Product Category hierarchy: child Product Categories/Subcategories are queried only when a parent is expanded, in bounded batches.
- Create Order: Product, Assignment and Workflow sections use viewport-triggered progressive section loading.
- Create Inquiry: the Product catalog section now uses the same viewport-triggered placeholder and does not run product/category search queries until the section approaches the viewport.
- Task Pack Setup: task packs/options are loaded after the setup shell.
- Team Performance: visible employees are batched with Load more.
- Client details: Orders, Documents and Activity queries run only for the active tab.
- Inquiry/Order details: detail queries are scoped to the active detail tab and opened modal/action.
- Search selects: remote option lists are queried only when needed rather than hydrating full user/client/supplier lists.
- Create Client: state options are now queried only for the currently selected office/billing/shipping countries instead of loading every active state in the workspace.
- Inquiry routing: list aggregate metrics are skipped when opening Create Inquiry or a single Inquiry detail because those screens do not display the list metrics.

## Dashboard

`dashboard.tagged-comments` is now a true viewport-lazy child component. If the user never reaches that section, its mention queries are not executed.

## Create Inquiry catalog boundary

Create Inquiry now keeps `createCatalogReady = false` until the Product section approaches the viewport. The existing `create-section-placeholder` calls `loadCreateSection('catalog')` through an `IntersectionObserver`. Only then does `BuildsInquiryPageData` run Product Catalog and Product Category queries. This mirrors the Create Order progressive section pattern and prevents an inquiry opened only to enter basic details from paying for catalog queries immediately.

## Server-load notes

Progressive rendering by itself improves initial response time but does not magically eliminate a query that the user eventually requests. Server load is actually reduced when a dataset is never requested, is paginated/batched, or is loaded only for an opened tab/expanded section. FlowTrack therefore combines page defer with existing on-demand query boundaries instead of replacing them with a cosmetic loader.

## Quality guard

Run:

```bash
npm run quality:page-loading
```

or directly:

```bash
php scripts/quality/progressive-page-loading.php
```

The check fails if a page-level Livewire component is added without `defer`/`lazy`, or if the centralized placeholder configuration is removed.
