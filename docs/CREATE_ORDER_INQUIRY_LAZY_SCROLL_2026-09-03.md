# Create Order Inquiry lazy loading and infinite scroll - 2026-09-03

## Scope

This update refines the optional Inquiry selector on Create Order without changing Order/Inquiry permissions, the multi-Inquiry persistence model, or Order creation transaction semantics.

## Behaviour

- Field title is **Search and Link Inquiry**.
- The **+ Add another inquiry** action is aligned to the right of the selected count.
- Opening Create Order does **not** query or preload Inquiry options.
- Clicking **Add another inquiry** or **Change** only reveals the picker; it still does not load Inquiry rows.
- Inquiry rows are requested only when the user explicitly opens the search selector.
- The first request stays compact (5 rows).
- The shared remote selector can opt into infinite-scroll paging. For this Inquiry selector, scrolling near the bottom automatically requests the next bounded page.
- Search remains remote and debounced. Search result pages remain bounded by `FilterOptionService::MAX_PER_PAGE`.
- Already selected Inquiry ids continue to be excluded by `exclude_ids`.

## Reuse

`resources/views/components/ui/search-select.blade.php` now accepts the optional `infiniteScroll` prop. Existing selectors keep the previous manual **Load more** behaviour because the new prop defaults to `false`.

## Performance

The Create Order data builder deliberately keeps `createInquiryFilterOptions` empty. This avoids Inquiry-table work when Create Order is opened and defers all Inquiry catalogue reads to `/filter-options/inquiries` only after explicit selector interaction.

## Files

- `resources/views/components/jobs/create/inquiry-link.blade.php`
- `resources/views/components/ui/search-select.blade.php`
- `resources/css/components/search-select.css`
- `resources/css/modules/orders/create.css`
- `tests/Feature/CreateOrderInquiryLinkTest.php`

Prebuilt CSS assets are included and the Vite manifest is cache-busted for deployment without a frontend rebuild.
