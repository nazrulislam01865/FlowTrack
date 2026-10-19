# Central Loading / Saving Feedback — 2026-08-26

## Goal

Use one consistent asynchronous feedback language across FlowTrack main content while keeping the sidebar untouched and keeping the interface usable during requests.

The two reference states are:

- **Saving… → Saved** for user changes.
- **Loading…** for filters, pagination, opening/refreshing data and other read operations.

Inline fields keep the feedback beside the edited field. Other foreground Livewire interactions use a compact non-blocking HUD below the top bar.

## UX rules

1. The sidebar is excluded.
2. Background polling does not show foreground feedback.
3. Very fast requests under 160 ms do not flash a loader.
4. Inline edits use the existing `x-ui.inline-save-state` component and now receive one centralized visual treatment.
5. A successful save shows `Saved` briefly, then clears automatically.
6. Failed inline saves keep the existing retry behavior. Generic foreground failures show `Couldn’t save` or `Couldn’t load` briefly.
7. The whole page or task row is not disabled while a field saves. Only the control being changed may remain disabled according to its existing feature logic.
8. Existing buttons that already replace their own label with `Saving…`, `Uploading…`, `Deleting…`, etc. are not given a duplicate global HUD.

## Central files

### Theme control

`resources/theme/flowtrack/settings.css`

Search for:

`Central async feedback`

This is the single place for feedback font size, weight, height, radius and Loading/Saving/Saved/Error colors.

### Shared feedback CSS

`resources/theme/flowtrack/components/async-feedback.css`

This owns:

- inline save badge appearance;
- global request HUD appearance;
- spinner animation;
- reduced-motion behavior;
- mobile HUD positioning;
- removal of old whole-row dimming / corner spinners on My Task / Task Board rows.

Feature CSS can still decide where an inline badge sits, but cannot redefine the feedback visual language.

### Shared behavior

`resources/js/components/async-feedback.js`

This owns:

- main-content interaction detection;
- sidebar exclusion;
- foreground Livewire request lifecycle;
- Saving vs Loading inference;
- flicker delay;
- success/error timing;
- optional per-control overrides;
- reusable Alpine async state factory.

### Browser API

`resources/js/core/browser-api.js`

Exposes:

`window.FlowTrack.ui.asyncState()`

for future custom Alpine controls that need the same local Saving/Saved/Error state.

### Shared Blade components

`resources/views/components/ui/async-feedback.blade.php`

One global foreground status HUD, mounted inside `<main>` only.

`resources/views/components/ui/inline-save-state.blade.php`

Shared local field feedback. It now also supports:

`retryable="false"`

for custom controls that should show `Not saved` without exposing a retry button.

### Application layout

`resources/views/layouts/app.blade.php`

Mounts `<x-ui.async-feedback />` after the topbar and inside the main content shell, so sidebar actions never use this UI.

## Optional control attributes

Most Livewire actions work automatically. These attributes are available when a feature needs an explicit override.

### Force feedback type

```html
<button data-ft-feedback-kind="saving">...</button>
<button data-ft-feedback-kind="loading">...</button>
```

### Disable global HUD for a control with its own local state

```html
<button data-ft-feedback="off">...</button>
```

### Force global HUD even when a button already contains a `wire:loading` label

```html
<button data-ft-feedback="global">...</button>
```

## Files changed

- `resources/js/app.js`
- `resources/js/components/async-feedback.js` (new)
- `resources/js/core/browser-api.js`
- `resources/theme/flowtrack/settings.css`
- `resources/theme/flowtrack/theme.css`
- `resources/theme/flowtrack/components/async-feedback.css` (new)
- `resources/views/components/ui/async-feedback.blade.php` (new)
- `resources/views/components/ui/inline-save-state.blade.php`
- `resources/views/layouts/app.blade.php`
- `resources/views/components/jobs/order-detail/shipping.blade.php`
- generated `public/build` app/theme assets were patched for immediate use in this archive because package installation was unavailable in the build environment. The source files above remain canonical; the next normal `npm run build` will regenerate the assets.
