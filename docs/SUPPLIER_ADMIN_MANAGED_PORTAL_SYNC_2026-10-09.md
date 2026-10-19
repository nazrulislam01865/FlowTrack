# FlowTracker ↔ Supplier Portal: admin-managed accounts and catalog synchronization

## Scope

FlowTracker is authoritative for supplier records, short codes, and existing product–supplier assignments. The Supplier Portal keeps its own database, authentication, sessions, APP_KEY, and storage. All transfers use a signed outbound HTTP API; neither app reads the other's database. **Client details, addresses, contacts, financial data, files, and client-related free-text fields are not synchronized.**

The supplier-specific order feed transfers only the order reference, the supplier's assigned product names, their quantities, and the requested date. The title is generated from the order reference rather than free-text FlowTracker order titles. No supplier actions or response endpoints are added.

## How admin knows which supplier is linked

In FlowTracker open **Master Data → Suppliers → supplier → Edit**. The existing supplier ID and short code are displayed in the new Supplier Portal Access section. **Only a FlowTracker administrator with the Suppliers: Manage permission** can create/update portal credentials. A successful API response records the portal username, login email and last link timestamp in *nonsecret* supplier metadata and displays these on the supplier detail page. The actual login password is hashed and stored **only** in the Supplier Portal database. The API binds the portal company to the exact `master_records.id`, not to a typed supplier short code.

This creates one primary admin login per synced supplier. Existing independent test invitations are not automatically reassigned to a different supplier. If a username/email already exists in another supplier company, the API refuses the operation (HTTP 409). To migrate a previously invited supplier, explicitly map the old company to the correct FlowTracker ID before provisioning; never guess from name/email.

## One-time configuration (keep existing .env files)

Main FlowTracker `.env`:

```env
FLOWTRACK_SUPPLIER_BRIDGE_ENABLED=true
FLOWTRACK_SUPPLIER_PORTAL_URL=http://127.0.0.1:8001
FLOWTRACK_SUPPLIER_BRIDGE_SECRET=<64-character hex secret from openssl rand -hex 32>
```

Supplier Portal `.env`:

```env
FLOWTRACK_BRIDGE_ENABLED=true
FLOWTRACK_SUPPLIER_BRIDGE_SECRET=<same secret>
```

Use HTTPS for remote servers; plain HTTP is allowed only on local loopback in a local/testing environment. **Neither `.env` nor `APP_KEY` should be replaced.**

Run migrations in both roots with `php artisan migrate`. This update adds only two supplier-portal fields: `supplier_users.username` and `supplier_companies.flowtrack_short_code`. Execute `php artisan config:clear` in each app. Keep both applications online. Do not use `migrate:fresh`.

## First synchronization

From the main FlowTracker root:

```bash
php artisan flowtrack:sync-supplier-catalog
```

This syncs existing suppliers and their assigned products without creating any login accounts. FlowTracker supplier create/edit also syncs the company/catalog after saving if the bridge is enabled. The supplier edit/detail screens have **Sync supplier & assigned products** for manual retry, and hourly reconciliation catches product assignment edits made outside the supplier form. A new order's delivery worker synchronizes its supplier catalog first, so a fresh order cannot be delivered to an unmapped supplier. The existing order delivery retry command and queue work as before:

```bash
php artisan flowtrack:sync-supplier-orders
```

**Automatic scheduling requires FlowTracker's Laravel scheduler.** Queue-worker availability influences the fast path; the scheduled retry handles worker outages. If migrations/configuration have not been completed in both applications, signed requests are refused instead of showing another supplier's information.

## Admin credentials

As a FlowTracker administrator with the Suppliers: Manage permission, you can optionally provide portal username/email/password in **Create supplier**; or open an existing **active** supplier → **Edit supplier** → **Supplier portal access**. Supply username, email, and strong password, then click **Create / update portal login**. The action first synchronizes the supplier and linked products, then signs an API request to create or update that supplier's primary portal admin user. You can log in to the Supplier Portal using either username or email plus password; reset-password remains email based. The supplier cannot change its FlowTracker supplier ID or see data assigned to another company. Existing portal sessions for a changed account are invalidated when credentials are replaced.

## Order data

The existing `supplier_order_deliveries` outbox remains durable and idempotent. Each supplier gets only assigned line items, and portal orders are scoped by `supplier_company_id`. The receiver constructs the display title from the order reference and does not persist free-text supplier instructions. Existing historical orders are not recreated by catalog sync; new order creation and pending outbox delivery supply orders.

## Testing & operations

1. `php artisan route:list --path=api/v1/integrations/flowtrack` in the Supplier Portal.
2. Run `php artisan migrate` separately for each app (non-destructive).
3. Run `php artisan flowtrack:sync-supplier-catalog` in FlowTracker; open the portal products page for your supplier account after provisioning.
4. Provision supplier A and create an order with one item assigned to A and another assigned to B. Verify each supplier sees only their own items.
5. Test duplicate username/email across suppliers: this must return a conflict rather than reassign the login.
6. Stop the portal, create an order, restart it, then use the retry command. Check that the order eventually appears only for the intended supplier.
7. Run `php artisan test --filter=FlowtrackSupplierCatalogApiTest` from the supplier app (requires a configured local test database and PHP extensions).

Neither the main FlowTracker `.env` (absent from the uploaded source ZIP) nor the uploaded Supplier Portal `.env` is changed by this implementation. The minimal patch ZIP never includes or replaces `.env` files. The complete project archive preserves the uploaded Supplier Portal `.env`; keep it private.
