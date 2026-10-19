# RFQ settings reminder prop fix - 2026-08-31

## Issue
Opening Supplier quotation settings triggered an `Undefined variable $rfqReminderEnabled` exception in the anonymous Blade component.

## Cause
`resources/views/components/inquiries-rfq-settings.blade.php` is an anonymous Blade component. Anonymous Blade components do not inherit arbitrary variables from the parent view scope. The component used `$rfqReminderEnabled` in an `@if` condition, but the parent rendered the component without passing that value.

## Fix
- Declared `rfqReminderEnabled` as an explicit component prop with a safe default.
- Passed the current Livewire `rfqReminderEnabled` state from `resources/views/livewire/inquiries/sections/detail.blade.php` into the component.
- Kept `wire:model.live="rfqReminderEnabled"` unchanged so toggling the setting still re-renders the reminder timing selector from the current Livewire state.

## Database
No migration is required for this fix.
