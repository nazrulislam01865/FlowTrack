# Supplier List Prototype Redesign — 2026-08-26

The Supplier list screen now follows the supplied FlowCheck supplier-management prototype while retaining FlowTrack's centralized theme tokens, permissions, Livewire architecture, and sidebar shell.

## UI

- Supplier list is a dedicated supplier screen, separate from Create supplier.
- Prototype header, action placement, three summary cards, search/status/export toolbar, supplier table, product-code chips, status badges, and View products link are reproduced.
- Responsive behavior keeps the toolbar and summary cards usable while the table scrolls horizontally on narrow screens.
- No page-local theme palette or font family was introduced.

## Reusable components

- `resources/views/components/suppliers/stat-card.blade.php`
- `resources/views/components/suppliers/product-tags.blade.php`
- `resources/views/components/suppliers/status-badge.blade.php`
- `resources/views/components/suppliers/list-row.blade.php`

## Modular implementation

- Supplier-list state/query/export behavior lives in `ManagesSupplierList`.
- Supplier-list markup lives in `sections/supplier-list.blade.php`.
- Supplier-list styling lives in `resources/css/modules/setup/master-data/supplier-list.css` and is composed from the master-data stylesheet entry.
- Summary and product-chip data is loaded in bounded queries; the Blade table does not execute per-row database queries.
- `View products` opens the Product list scoped to the selected supplier using the existing catalogue page.
