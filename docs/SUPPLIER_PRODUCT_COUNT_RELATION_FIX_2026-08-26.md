# Supplier product count relation fix - 2026-08-26

## Problem
Supplier List could show `0 products` even when Product Master records had suppliers linked. The multi-supplier implementation previously depended only on JSON metadata (`supplier_id`, `default_supplier_id`, `supplier_ids`), which is not a reliable normalized many-to-many source for counting and filtering.

## Fix
- Added normalized `product_supplier_links` table with a unique Product/Supplier pair.
- Migration backfills links from legacy Product metadata and supported reverse Supplier product tags.
- Product bulk supplier assignment dual-writes the normalized link and compatibility metadata.
- Create Supplier product-code assignment dual-writes the normalized link and compatibility metadata.
- Supplier List summary/counts/product chips merge normalized links with legacy metadata so existing installations remain compatible.
- Supplier export, Product supplier display, supplier filters, and supplier assignment modal counts use the normalized links when available.
- Existing default supplier behavior remains unchanged: `metadata.supplier_id` is still the Product Master default used by Create Order/Inquiry.

## Deployment
Run the normal migration step after deploying:

`php artisan migrate --force`

The migration is idempotent at the relationship level because Product/Supplier pairs are unique and the backfill uses insert-or-ignore semantics.
