# Central async feedback — contextual placement fix

Date: 2026-08-26

## Problem

The first centralized async feedback implementation treated ordinary `wire:model` / change requests as persistence. On create forms this produced a green **Saved** badge even though the user had only changed temporary Livewire form state. The fallback badge was also fixed to the top-right of the main content, so it was visually disconnected from the control that caused the request.

## Correct behavior

FlowTrack now separates three cases:

1. **Draft/form state sync** — no Saving/Saved feedback. Example: entering an Order hand date, choosing a temporary form option, or changing a normal `wire:model` value.
2. **Read/loading request** — show **Loading…** only when the request visibly loads content (search/filter/fetch/refresh/export etc.).
3. **Real persistence** — show **Saving…**, then **Saved** only for explicit create/update/save/complete/cancel/delete/etc. actions.

Existing field-level `<x-ui.inline-save-state>` indicators remain the preferred UI. The generic fallback is used only when a feature has no local loading/saving copy.

## Placement

The centralized fallback is still one reusable component, but JavaScript now positions it relative to the initiating control. It prefers the right side of compact controls, then left, below, or above depending on viewport space. It follows scroll/resize and is clamped inside the main-content viewport. Sidebar interactions remain excluded.

## Central files

- `resources/js/components/async-feedback.js` — request classification, intent tracking, contextual positioning and lifecycle.
- `resources/theme/flowtrack/components/async-feedback.css` — centralized visual language only.
- `resources/views/components/ui/async-feedback.blade.php` — reusable contextual feedback host.
- `resources/theme/flowtrack/settings.css` — centralized feedback tokens/colors/sizes.

## Important rule for future features

Do not use Saving/Saved for a plain property sync. If a custom action needs an explicit semantic override, use:

```html
<button data-ft-feedback-kind="saving" wire:click="saveSomething">Save</button>
<button data-ft-feedback-kind="loading" wire:click="refreshSomething">Refresh</button>
<div data-ft-feedback="off">...</div>
```

Buttons/components that already contain their own `wire:loading` text remain self-contained and do not receive a duplicate fallback badge.

## Built assets

The included `public/build` JavaScript and theme CSS were updated so the fix works immediately without requiring a local Vite build. A future normal Vite build will use the corrected source implementation.
