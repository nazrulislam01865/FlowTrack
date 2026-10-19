# FlowTracker -> Supplier Portal: Order-created API integration

## Scope

This change is one-way and read-only on the supplier side. After an **active** order is created, FlowTracker stores one outbox event per assigned supplier. Each event contains only that supplier's line items (product name and quantity), an order reference/title, supplier instructions, and a target date if present. Unassigned items and other suppliers' products are excluded. The portal saves a copy in its own `supplier_resources` table and displays it in Orders & Requests, read-only order details, and Notifications.

No supplier action/status write-back, file sharing, financial information, account provisioning, buyer contacts, or legacy order backfill was implemented. Draft orders are intentionally excluded. Existing RFQ and authentication flows are unchanged.

## Security boundary

- Dedicated Laravel applications and independent MySQL users remain mandatory. No shared database connection, root Laravel key, vendor filesystem, or login session.
- A 32+-character **dedicated shared signing secret** authorizes FlowTracker's server-only HTTPS POST to `POST /api/v1/integrations/flowtrack/orders` on the supplier service. The exact raw JSON request body is HMAC-SHA256 signed together with its event UUID and Unix timestamp. The receiver rejects invalid signatures and timestamps older than 5 minutes.
- Local development alone permits `http://127.0.0.1:8001`; production egress must use HTTPS and network allowlisting. Never expose FlowTracker's database or web admin APIs to the supplier environment. Enforce separate Unix users/PHP-FPM pools or containers in production.
- The receiving company is determined from an **administrator-maintained unique FlowTracker supplier ID mapping**, never from user sessions, supplier-controlled input, or unauthenticated lookup. Unmapped/inactive suppliers result in HTTP 409 and remain in FlowTracker's retry outbox.
- The portal reads only its own company-scoped `supplier_resources` and returns minimal projections. Re-delivery of the same order is safe due to the existing unique company/type/order reference index.
- Signed POSTs are explicitly stateless Laravel API routes; CSRF-based browser routes remain unchanged.

## Existing deployments: apply files without replacing either `.env`

**Back up both working `.env` files first.** This patch does not replace either file or rotate existing APP_KEYs. Never copy the FlowTracker `.env` into the supplier service. Run new migrations in **both** separate databases; never use `migrate:fresh`.

### Step 1: Migrate each application

```bash
# Run from the FlowTracker project root (main app)
php artisan migrate --force

# In a separate shell, from the isolated supplier-portal directory
php artisan migrate --force
```

### Step 2: Dedicated API secret

Generate one new, random, **different from APP_KEY** signing secret on a trusted administrator machine:

```bash
openssl rand -hex 32
```

Append the following to the **existing main FlowTracker `.env`** (keep all other values unchanged):

```dotenv
FLOWTRACK_SUPPLIER_BRIDGE_ENABLED=true
FLOWTRACK_SUPPLIER_PORTAL_URL=http://127.0.0.1:8001
FLOWTRACK_SUPPLIER_BRIDGE_SECRET=PASTE_THE_GENERATED_SECRET
```

Append the following to the **existing supplier-portal `.env`**:

```dotenv
FLOWTRACK_BRIDGE_ENABLED=true
FLOWTRACK_SUPPLIER_BRIDGE_SECRET=PASTE_THE_SAME_GENERATED_SECRET
```

For production set `FLOWTRACK_SUPPLIER_PORTAL_URL` to your supplier portal's **HTTPS** host, restrict supplier API ingress to the FlowTracker egress IP/network, and limit FlowTracker egress to this one HTTPS destination. Never use publicly reachable `artisan serve` outside local testing.

Run `php artisan config:clear` from **each** application's own directory. Do not regenerate application keys or touch existing database credentials.

### Step 3: Link suppliers once

Find the **numeric** FlowTracker supplier master record ID for the intended supplier. It is the `master_records.id` where `type='supplier'`. Inspect with your normal FlowTracker admin screen or, using your FlowTracker database credentials:

```sql
SELECT id, code, name FROM master_records WHERE type = 'supplier' AND status = 'active' ORDER BY id LIMIT 50;
```

Within `supplier-portal`, link the existing portal account (example `SUP001`) to that specific numeric ID:

```bash
php artisan supplier:link-flowtrack SUP001 182
```

**182 is only a placeholder**; replace with the real numeric ID from the main application. Neither application reads the other's DB. One FlowTracker supplier ID can be linked to only one supplier company.

### Step 4: Run both applications and retry worker/scheduler

During local testing:

```bash
# Terminal 1: FlowTracker main app
php artisan serve --host=127.0.0.1 --port=8000

# Terminal 2: supplier-portal
php artisan serve --host=127.0.0.1 --port=8001

# Terminal 3: main FlowTracker app
php artisan flowtrack:sync-supplier-orders
```

After creating one **new non-draft order** containing at least one line assigned to the linked supplier, the supplier logs in and refreshes `http://127.0.0.1:8001/`. The Orders & Requests table and Notifications should show the new order with **only that supplier's lines**. For automated retries use the **existing FlowTracker schedule** (`php artisan schedule:work` in local, or the existing production cron/worker) and main app queue worker. An asynchronous job is queued after commit if the queue driver is not `sync`; if the queue driver is `sync`, the scheduler alone sends orders without blocking a web request with HTTP.

FlowTracker outbox verification SQL (run only against the **main** database):

```sql
SELECT flow_job_id, supplier_id, status, attempts, last_error FROM supplier_order_deliveries ORDER BY id DESC LIMIT 20;
```

- `pending`: saved; awaiting first delivery or backoff retry (no loss if supplier service is down).
- `delivered`: receiver acknowledged HTTP 2xx.
- `HTTP 409`: supplier company mapping is missing, inactive, or incorrect; fix mapping and retry after backoff.
- `HTTP 401`: signatures/time/secret mismatch; verify system clock and both signing secret values.

Retry is bounded by exponential backoff up to 60 minutes. Never share the secret, application `.env`, reset links, or supplier login passwords in logs or chat.

## Compatibility and limitations

- Only **newly created, non-draft** orders are published. Changing a supplier on an existing order, activating a draft, updating an order, or canceling a delivered order **does not** synchronize yet. Those actions require separately approved events and rules.
- Receipt is an informational snapshot, not an actionable order lifecycle. No supplier update/write-back endpoints have been opened.
- A misconfigured API secret or missing mapping does not remove a FlowTracker order; pending deliveries remain queryable in the local outbox.
- PHP lint, supplier route discovery and view compilation may be run without a database. Full end-to-end verification requires both application databases, reachable HTTP processes, queue/scheduler, and the required PHP testing extensions.
