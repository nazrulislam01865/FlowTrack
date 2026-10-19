# Order Workflow Email Team Recipient + Exact Preview — 2026-08-27

## Scope

This update keeps the existing real email handoffs and changes recipient resolution to match the operational rules requested for the Order workflow:

- `NEW_SEND_PO_ARTWORK` — Send Purchase Order to Artwork Team
- `ART_SEND_ORDER_TEAM` — Send Artwork to Order Team

## Recipient resolution

### Send Artwork to Order Team

Recipients now come from **Administration > Users & role assignments**.

Every active workspace user whose assigned role is **Order Team** is included. The lookup accepts the equivalent role identity forms `Order Team`, `order-team`, and `ORDER_TEAM`, and it uses the multi-role `user_roles` assignment as the canonical source with the legacy `users.role_id` retained only as a compatibility fallback.

This means the handoff can go to multiple Order Team members. It no longer depends on a single destination task assignee, Order owner, coordinator, or department fallback.

### Send Purchase Order to Artwork Team

Recipients now come from the current Order itself. The service locates that Order's **Artwork phase** from its Artwork workflow automation keys, then collects every unique active user assigned to tasks in that phase.

This means the Purchase Order is sent to the actual assignees participating in Artwork for that specific Order rather than to a hard-coded address or generic department mailbox.

All recipients must be active users with valid email addresses. Duplicate email addresses are removed.

## Exact email preview

The confirmation modal shows:

- every recipient name and email address;
- the recipient-selection rule used for that handoff;
- actual sender account;
- reply-to address;
- subject;
- attachment filename;
- configured delivery service;
- a sandboxed `srcdoc` rendering of the exact `emails.orders.workflow-handoff` Blade email that will be sent.

The preview and actual delivery share the same recipient resolver, subject builder and email Blade/view data, so the list shown before Send is the list passed to the centralized `EmailService`.

## Delivery safety

The existing synchronous `EmailService::sendNow()` behavior is preserved. The workflow task is completed only after the configured transport accepts the email.

No database migration is required.
