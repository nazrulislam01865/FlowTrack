# Order Details Product Action UX — 2026-08-21

The Products & quantities table no longer uses an ambiguous three-dot action for the only available row action.

## Change

- Replaced `•••` with a compact `Edit` button and pencil icon.
- Kept the existing `openEditOrderProductModal()` Livewire method and all product persistence logic unchanged.
- Kept Restore behavior for removed products unchanged.
- Styled the button using the Order Details teal interaction theme.
- Added keyboard focus state and an explicit accessible label/title.

No migration or backend change is required.
