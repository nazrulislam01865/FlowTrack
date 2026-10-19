# FlowTracker Supplier Portal: product-scoped order synchronization

## Implemented scope

Only active, non-draft orders with **explicitly assigned catalog products and their selected suppliers** produce portal order events. The selected `flow_job_items.supplier_id` must be linked to the same catalog product via the existing `product_supplier_links` relationship or the existing legacy product metadata supplier assignment. The product and supplier must belong to the same workspace; inactive or deleted suppliers are excluded.

For each order, FlowTracker creates one delivery event per **actual supplier on that order's eligible product lines**, containing only those lines. A product linked to multiple suppliers goes **only to the supplier selected on that order item**; it is not broadcast to every supplier linked to the product.

- No products / no selected supplier / unmatched product-supplier relationship: **no outbound order event**.
- One supplier across multiple products: **one event** containing only that supplier's products and quantities.
- Multiple suppliers: **one independently scoped event per supplier**.
- Removed order item: **not included**.
- No order-level `supplier_id` fallback; no supplier can receive an unassigned product merely because the order has a header supplier.
- API payload: only order reference, generated title, creation date, target date, canonical catalog product names and order quantities (not free-text line labels). No client/customer data, contact, address, price, notes, files, or credentials.

To avoid new N+1 queries, order capture uses a single query for its eligible product rows and supplier assignment checks. The existing durable `supplier_order_deliveries` outbox, signed API, and retry scheduler remain in use. Before sending, the existing API delivery service synchronizes **only the delivery's supplier catalog**, not all suppliers. If that supplier has no linked products at delivery time, it does not send the order and retains the pending delivery for a later retry.

The catalog command now counts suppliers with no assigned products as **skipped** (not failed) and sends no API request for them. Supplier portal login provisioning is also refused until a product is assigned. No database migrations, new tables, supplier portal endpoint changes, or `.env` changes are needed.

## Deploy and test

1. Merge the changed files **over the existing main FlowTracker root**; do not replace the existing `.env` or supplier-portal directory.
2. From the main FlowTracker root, run:

```bash
php artisan config:clear
php artisan flowtrack:sync-supplier-catalog
```

3. Create a **new non-draft test order** with product P assigned to supplier A and another product Q assigned to supplier B. Confirm that A's portal shows only P and B's portal shows only Q. Ensure a linked product with multiple possible suppliers is visible only to its **selected order-line supplier**.
4. Trigger delivery retries if necessary:

```bash
php artisan flowtrack:sync-supplier-orders
```

5. In FlowTracker's own database, inspect routing without exposing private payloads:

```sql
SELECT flow_job_id, supplier_id, status, attempts, last_error
FROM supplier_order_deliveries ORDER BY id DESC LIMIT 20;
```

Orders previously delivered are not withdrawn or reassigned by this update. An existing pending outbox event retains its stored snapshot. The patch intentionally does not add updates, cancellations, historical replays, or supplier action endpoints. End-to-end verification requires both local Laravel apps and databases; these cannot be executed in this offline delivery environment.

## Delivery package scope

The uploaded `Archive 2(6).zip` contains the **main FlowTracker application only** (no `supplier-portal/` folder). Accordingly, the full-source ZIP produced for this change contains the updated main application only. The supplier portal's existing installation is not replaced or modified; the existing integration API endpoints remain compatible.
