# Order Redo header action alignment — 2026-08-25

## Requested change
Move the `↻ Initiate Redo` action from the separate upper-right header area into the same Order Details command bar used by `Flag order` and `Cancel order`.

## File changed
`resources/views/components/jobs/order-detail/header.blade.php`

## What changed
- `Initiate Redo` is now rendered after the command-bar spacer and immediately before `Flag order` and `Cancel order`.
- It now uses the shared compact button sizing (`btn ... small`) used by the other two command-bar actions.
- The original separate right-side Redo button block was removed.
- Existing Redo permission logic (`$canInitiateRedo`) and Livewire action (`openRedoModal`) are unchanged.
- No Redo business logic, workflow, modal, database, finance, task, or permission behavior was changed.

## Final action order
`↻ Initiate Redo` → `⚑ Flag order` → `⊘ Cancel order`
