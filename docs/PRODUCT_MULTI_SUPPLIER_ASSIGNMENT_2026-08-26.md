# Product multi-supplier assignment — 2026-08-26

- Product list bulk selection now exposes **Assign supplier** using the supplied prototype interaction pattern.
- One active supplier can be linked to all selected products in one transaction. Existing supplier links are preserved.
- Product metadata stores all links in `supplier_ids`; `supplier_id` remains the explicit default supplier for existing Order/Inquiry flows.
- Creating a supplier with product codes appends the supplier link instead of replacing existing suppliers.
- Supplier list counts, product chips, export, product filtering, and supplier-product navigation are multi-supplier aware.
- Product list renders linked suppliers without N+1 supplier queries.
- No database migration is required because the relationship is stored in the existing JSON metadata column.
