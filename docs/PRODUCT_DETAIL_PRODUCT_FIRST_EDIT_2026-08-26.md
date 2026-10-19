# Product Detail Product-First Edit — 2026-08-26

## Scope
Updated the Products & quantities inline edit flow on both Order Details and Inquiry Details.

## Behaviour
- Edit starts with a Product Master search rather than category/product dependent dropdowns.
- Search supports product name, product code and reference code.
- Selecting a product is the source of truth for its category and quantity-tier base price.
- Order Details also loads the Product Master default supplier and permits an order-specific supplier change.
- Inquiry Details shows the Product Master default supplier without creating a separate inquiry supplier override.
- Changing quantity recalculates the quantity-tier base price.
- Starting a replacement-product search clears stale dependent values until a result is selected.
- Duplicate products are rejected while excluding the row currently being edited.
- The same reusable Blade editor is used by both detail pages.

## Reusable component
`resources/views/components/catalog/detail-product-edit.blade.php`
