# Shared forms, filters, search and selection

## SearchSelect

Use `x-ui.search-select` for searchable single selection. Set `type` for a remote selector; omit `type` and provide `options` for a small local list.

```blade
<x-ui.search-select
    label="Client"
    property="clientId"
    type="clients"
    context="orders"
    :value="$clientId"
    :initial-options="$clientOptions"
    placeholder="All clients"
/>
```

Large catalogs must use a remote `type`. Do not fetch the complete catalog in Livewire just to feed this component.

## MultiSelect

Use `x-ui.multi-select` for bounded remote multi-selection:

```blade
<x-ui.multi-select
    label="Select clients"
    property="selectedClientIds"
    type="clients"
    context="workflow-setup"
    :values="$selectedClientIds"
    :initial-options="$clientOptions"
/>
```

Selected IDs are sent back to the server separately so labels remain available even when those rows are not on the current result page.

## Filter composition

Use `x-ui.filter-bar` as the container, `x-ui.search-input` for free-text search, `x-ui.filter-chip` for quick states, `x-ui.date-range` for date filters and `x-ui.filter-reset` for the one reset action.

Feature CSS may position these primitives but must not restyle their visual contract.

## Remote query contract

`FilterOptionService::searchPage()` is the only new remote selector query API. It is permission/context scoped, page bounded, selected-value aware, and returns no unrelated fallback items for incomplete or no-match search states.

The compatibility `options()` method delegates to `searchPage()` and is temporary. New selector work should prefer the paged API.
