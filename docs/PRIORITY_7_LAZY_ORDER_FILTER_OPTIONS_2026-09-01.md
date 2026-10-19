# Priority 7 - Lazy Orders Filter Options

Date: 2026-09-01

## Objective

Stop the Orders list from querying bounded pages of Clients, Owners, Assignees and Suppliers during every Livewire render. These datasets are needed only when a user opens the corresponding remote dropdown.

## Previous behavior

`App\\Livewire\\Orders\\Index::render()` called `FilterOptionService::options()` for five remote filter datasets on every render:

- Clients
- Owners
- Stage assignees
- Stage clients
- Suppliers

Each call resolved the selected item and also loaded a page of recent options. Pagination, searching, stage changes and other Livewire updates could therefore repeat filter-option queries even when no dropdown was opened.

## New behavior

The Orders render now passes only `selectedFilterOptions()` to each remote selector.

- If the filter is empty, an empty collection is returned and no option query is executed.
- If a filter is active, only the selected row is resolved so its visible label remains correct after refreshes, URL/deep-link state and Livewire morphs.
- When the user opens the dropdown, the existing shared `x-ui.search-select` runtime calls the existing `filter-options` endpoint and loads the first bounded remote page.
- Search remains remote and debounced exactly as before.

## New service API

`FilterOptionService::selectedOptions()` resolves selected option rows through the same existing type/context-specific permission-aware resolver used by `searchPage()`, without calling the recent-option `window()` query.

`searchPage()` also reuses this method to avoid duplicating selected-row resolution logic.

## Data intentionally kept live

This change does not cache or alter:

- Orders
- Order status
- Task status
- Task assignee state
- Workflow stage counts
- Payments or invoices
- Documents
- Artwork state
- Filter query semantics
- User permissions

## UI behavior

No visual redesign was made. The existing remote filter components, menus, pagination, search behavior, selected labels and clear-filter behavior remain in use.

## Verification

Static regression coverage was added in `tests/Feature/OrdersLazyFilterOptionsImplementationTest.php` to verify:

1. Orders no longer calls `FilterOptionService::options()` for the five remote filter datasets during render.
2. Selected rows use `selectedOptions()`.
3. The shared remote selector still loads options from the network when opened.

The distributed archive does not include Composer `vendor/`, so the full Laravel test suite must be run after dependency installation or in CI/production staging.
