# Supplier create page redesign — 2026-08-26

## Scope
- Supplier navigation is now an expandable sidebar group with **Supplier list** and **Create supplier** child links.
- Creating a supplier is a dedicated full-page flow (`master-data?group=supplier&create=1`) instead of the generic Master Data modal.
- The new page follows the supplied supplier-management prototype while inheriting FlowTrack's centralized theme tokens, font family, shell, spacing, and responsive behavior.

## Reusable UI
- `resources/views/components/suppliers/form-card.blade.php`
- `resources/views/components/suppliers/field.blade.php`
- `resources/views/components/suppliers/side-card.blade.php`
- `resources/views/livewire/master-data/sections/supplier-create.blade.php`
- `resources/css/modules/setup/master-data/supplier-create.css`

## Logic
`ManagesSupplierCreation` owns the dedicated create-page state and behavior. Supplier contact data is stored in supplier metadata. Optional product codes are validated against Product Master records and assigned to the new supplier in the same database transaction.

The existing Supplier list and existing supplier edit behavior remain intact.
