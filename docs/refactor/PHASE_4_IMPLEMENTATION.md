# Phase 4 implementation — Shared forms, filters, search and selection architecture

## Objective

Phase 4 gives FlowTrack one interaction architecture for searchable single-selects, bounded remote multi-selects, search inputs, date/date-range filtering, filter bars/chips/reset behavior, validation, server error feedback and inline-save feedback. The implementation preserves existing Livewire property names and feature semantics while replacing page-specific selector behavior.

## Authoritative selector boundary

`App\Services\FilterOptionService::searchPage()` is the shared server-side selector contract. `App\Support\Filters\FilterOptionPage` defines its response shape. The contract returns `items`, `selected_items`, page metadata and minimum-search metadata. Remote result pages are capped at 20 rows, selected values are resolved separately, and selected IDs are capped at 100.

An incomplete non-empty search is intentionally empty. A one-character query never falls back to unrelated recent values. A valid no-match search also returns no random replacement option.

`FilterOptionService::options()` remains a temporary compatibility adapter for existing Livewire screens. It delegates to the same bounded paged architecture and does not create a second query implementation.

## Official UI contracts

Phase 4 promotes these reusable contracts:

- `x-ui.search-select` — one local/remote searchable single-select API.
- `x-ui.multi-select` — local/remote multi-select with selected-value preservation and remote pagination.
- `x-ui.search-input` — shared debounced search field.
- `x-ui.filter-bar` and `x-ui.filter-chip` — shared filter composition.
- `x-ui.filter-reset` — one clear/reset interaction.
- `x-ui.date-range` — shared date/date-range filtering.
- `x-ui.validation-message`, `x-ui.server-error` and `x-ui.inline-save-state` — shared feedback semantics.

`remote-filter`, `remote-select`, `select-filter`, `date-range-filter` and `list-search` are compatibility-only APIs. New feature pages must not use them. `shipping-address-editor` temporarily retains the old select adapter because its address/state interaction has a separate migration risk. `multi-role-select` also remains a specialized compatibility component until its dedicated visual migration.

## Browser runtime

`public/js/flowtrack-list-filters.js` now provides the shared `FlowTrackSearchSelect` and `FlowTrackMultiSelect` runtimes. They reuse the same request/pagination behavior, cancel stale requests with `AbortController`, keep selected labels stable while requests are pending, expose a common `visibleItems` rendering API and support explicit `Load more` paging.

## Production migrations completed

Phase 4 migrates representative high-value consumers instead of shipping unused primitives:

- Document Archive: Client, Uploaded By and Upload Order use bounded server-side options.
- Workflow Setup: Specific Clients uses the shared remote MultiSelect.
- Product create/edit: Selected Clients uses the shared remote MultiSelect instead of preloading the complete active-client catalog.
- User Editor and Administration: Department uses the shared SearchSelect.
- Inquiry list: Search, filter bar/chips, status/client selectors, date range and clear/reset use shared primitives.
- Orders list: filter composition uses the Phase 4 shared primitives while preserving existing Livewire property names.
- Client order filtering: search/status/owner selection uses the shared interaction contracts.

## Query rules

1. Large user/client/product/order catalogs are remote and bounded.
2. Selected values are resolved by ID separately from current page results.
3. Search does not return unrelated fallback data when there is no match.
4. Permission/workspace scope is applied inside the shared option service before rows are mapped to UI options.
5. Feature-specific context remains an explicit parameter so business-specific visibility can be preserved without duplicating selector code.
6. Compatibility adapters may translate old Livewire property names, but may not own a second query implementation.

## Quality gate

Run:

```bash
npm run quality:phase4
```

This runs the Phase 0–3 gates and `scripts/quality/phase4-shared-filters.php`. The Phase 4 gate verifies the paged query contract, official components/CSS/runtime, representative migrated consumers, absence of new ordinary-page use of deprecated selector components, and zero hard-coded colors/`!important` in the new Phase 4 component CSS.

## Release notes

This phase does not decompose the giant Jobs/Inquiries coordinators; that begins in Phases 5 and 6. It deliberately preserves current business state/property names so Phase 5 can extract Orders workflows on top of stable form/filter contracts.

Full PHPUnit and authenticated visual regression remain release gates in a dependency-complete environment. Do not re-baseline failing tests or visual snapshots merely to make the refactor pass.


## Test reconciliation update

A dependency-complete user run built the Vite production bundle successfully but exposed 94 PHPUnit failures. Phase 4-specific source-assertion defects and shared-component expectation drift were corrected in the test-fix package. See `docs/refactor/PHASE_4_TEST_RECONCILIATION.md`. The remaining inherited failures must be reconciled against the approved application baseline rather than silenced by changing current business behavior.
