# Saving Feedback Scope Fix - 2026-08-26

## Problem

The global async-feedback classifier inferred `saving` from method-name words such as `create`, `save`, `delete`, and `restore`.

That made UI-only actions such as `openCreate` look like persistence operations. On the Clients list, clicking **New Client** triggered a Livewire round-trip to open the create screen and the global badge displayed `Saving...` followed by `Saved`, even though no client had been persisted.

## Policy after this fix

Global async feedback is now conservative:

- `Saving... / Saved` is **explicit opt-in only** via `data-ft-feedback-kind="saving"`.
- Data-entry forms remain globally silent and keep their own button/upload/validation feedback.
- Inline edits continue to use the existing `x-ui.inline-save-state` component, which is tied to the actual save promise and can correctly show `Saving...`, `Saved`, and error/retry states.
- UI-only actions (`open*`, `close*`, `show*`, `hide*`, `toggle*`, `select*`, `reset*`, `clear*`, `set*`, `change*`, tab actions) are silent.
- Global `Loading...` remains available for genuine retrieval/rebuild operations whose method names contain `load`, `fetch`, `search`, `filter`, `sort`, `page`, `refresh`, `preview`, `export`, or `query`.
- Unknown clicks are silent instead of being guessed as a save/load operation.
- Sidebar behavior remains excluded.

## Why this is safer

A successful Livewire request only proves that the request completed. It does not prove that a database record was created or updated. Validation, modal opening, state synchronization, and navigation can all return successful requests.

Saving feedback must therefore be connected to a known persistence operation rather than inferred from a method name.

## Files changed

### `resources/js/components/async-feedback.js`

The global classifier no longer contains a persistence-word heuristic. `Saving... / Saved` can only be requested explicitly.

### `public/build/assets/app-3eb5b9ae.js`

The existing release bridge was updated with the same policy so the distributed ZIP works immediately without requiring a Vite rebuild.

## Where saving feedback still appears

The project already has purpose-built inline save feedback in the areas where it is useful, including:

- Order task assignee and due date inline edits
- Order planning/ownership inline edits
- Order header/overview inline edits
- Inquiry task assignee, due date, and status inline edits
- Inquiry detail inline edits
- My Task inline edits
- Task-detail properties and description
- Board task/job inline edits

These use `resources/views/components/ui/inline-save-state.blade.php` and are driven by the actual inline-edit promise, not by the global request classifier.

## Future use

If a future non-form action genuinely needs the global save badge, opt in on that exact trigger:

```html
<button
    type="button"
    wire:click="somePersistingAction"
    data-ft-feedback-kind="saving"
>
    Save
</button>
```

Do not add that attribute to buttons whose purpose is only to open a page, modal, picker, or preview.
