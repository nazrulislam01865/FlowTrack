# Search Input + Job Document Filter Fix

## Search focus stability
- Jobs search no longer destroys/recreates the input on every keystroke. The full result render is debounced until typing pauses.
- Board search keeps its toolbar/input mounted and refreshes only the kanban result body + result summary.
- My Work search keeps its toolbar/input mounted and refreshes only the work board + footer summary.
- This prevents keyboard focus/caret loss while typing.

## Job Details > Documents
- Simplified the Job documents list toolbar to only:
  - Search
  - Document filter
- Removed Phase, Task, Status, Owner, More filters, sort, List/Grid and Group-by controls from this list toolbar.
- Replaced the text search glyph with one SVG search icon, eliminating the duplicate search icon.
- Existing phase/task grouping and document requirement sections remain unchanged.

## Files changed
- `resources/js/flowtrack/modules/operations.js`
- `resources/js/flowtrack/modules/job-shared.js`
- `resources/css/flowtrack/runtime/18-search-document-filter-fix.css`
- `resources/css/flowtrack/app.css`
- generated `public/flowtrack/*` runtime assets
- compiled CSS manifest asset updated for cache busting
