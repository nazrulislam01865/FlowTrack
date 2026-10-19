# Order Details: Production date full-field calendar trigger (2026-08-28)

## Scope

Order Details -> Production -> **Set estimated delivery date** workflow action.

## Change

The estimated delivery date input now explicitly calls the browser date picker's
`showPicker()` API from a trusted click event. This makes the full visible date
input surface open the calendar instead of requiring the user to click the small
calendar icon.

The implementation keeps the existing native `input[type=date]`, Livewire
binding, validation, workflow gating, and submit behavior unchanged. Browsers
without `showPicker()` retain their normal native date-input behavior.

A pointer cursor is applied only to this Production workflow date input so the
clickable behavior is visually clear.
