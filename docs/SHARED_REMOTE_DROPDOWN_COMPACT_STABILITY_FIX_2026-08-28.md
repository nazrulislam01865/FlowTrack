# Shared remote dropdown compact stability fix — 2026-08-28

## Problem
Searchable remote dropdowns could become much taller after repeated use. A menu that had previously searched or loaded additional pages could keep the expanded client-side rows until the next request completed. This was especially visible in the Order owner picker, where reopening the selector could temporarily show 20 "recent" options and a large panel.

## Central fix
The shared dropdown runtime in `resources/js/components/list-filters.js` now owns one compact behavior for all remote single-select and remote multi-select controls:

- normal/recent page size is always 5;
- search page size remains 20;
- closing or reopening restores the compact recent page immediately;
- search results and Load more pages never leak into the next open;
- stale requests are aborted and invalidated on close;
- menu positioning state is reset on close;
- searchable dropdown height is centrally capped at 280px and the option list scrolls inside it.

The same height contract is reflected in the shared component CSS, including the legacy shared-filter layer, so page-specific styles cannot make the panel grow unexpectedly.

## Scope
This applies centrally to components powered by `FlowTrack.ui.searchSelect` and remote `FlowTrack.ui.multiSelect`, including inline user/owner selectors. Business logic, selected values, search behavior, permissions, and server-side option filtering are unchanged.
