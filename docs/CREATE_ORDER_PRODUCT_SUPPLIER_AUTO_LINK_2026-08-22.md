# Create Order Product Supplier Auto-Link Update

## Behavior

- Create Order never asks the user to choose a supplier for a selected product.
- Supplier is resolved only from the Product Master record.
- If the configured supplier is missing, inactive, or deleted, the product is not added to the Order.
- A blocking message tells the user: **Supplier is not linked to this product. Contact your admin to link a supplier with the product.**
- Existing/stale Create Order rows with no supplier show the same read-only warning rather than a supplier selector.
- Immediately before Order creation, supplier IDs are re-resolved from Product Master so browser state cannot override the Product -> Supplier relationship.

## Files

- `app/Livewire/Jobs/Index.php`
- `resources/views/components/catalog/create-order-product-card.blade.php`
- `resources/views/components/jobs/create-products.blade.php`
- `resources/css/legacy/compatibility/flowtrack-order-create-products.css`
- `tests/Feature/CreateOrderProductSupplierUxTest.php`

No database migration is required.
