# Filter Dropdown Viewport Fix

## Updated behavior

- Searchable select menus no longer use the old fixed 235px option-list height.
- Dropdown height is calculated from the currently available browser viewport.
- When all options fit in the available space, they are shown without an internal scrollbar.
- If the list is taller than the available viewport, only then does the option list scroll.
- Dropdowns automatically choose down/up direction based on which side has enough room.
- Geometry is recalculated on resize, viewport changes and scrolling.
- Small-screen filter menus can use almost the full visible viewport instead of the previous 50-60vh cap.
- Existing search, selection, change events and backend filtering behavior remain unchanged.

## Main files changed

- `resources/js/flowtrack/searchable-dropdown.js`
- `resources/css/flowtrack/vendors/searchable-dropdown.css`
- `resources/css/flowtrack/runtime/08-responsive.css`
- `public/flowtrack/searchable-dropdown.js` (generated)
- `public/build/manifest.json`
- `public/build/assets/app-e1bca9f5.css`
