# Order Tracking backend — Phase 1 fetch boundary (2026-10-01)

## Scope

This phase intentionally stops at **secure data retrieval**. It does not redesign the approved tracking screens, add new public fields, or decide audience-specific status visibility for internal users/suppliers.

The live `/track-order` page keeps its existing customer-facing disclosure scope while the backend can now resolve additional order-linked identities internally for the next phase.

## Request/read path

```text
POST /track-order
  -> OrderTrackingController (transport validation only)
  -> PublicOrderTrackingQuery (compatibility facade)
  -> PublicOrderTrackingFetchService (application/business boundary)
       -> PublicOrderTrackingCandidateQuery
       -> PublicOrderTrackingIdentityResolver
       -> PublicOrderTrackingDisclosurePolicy
       -> PublicOrderTrackingDataQuery
       -> PublicOrderTrackingSnapshotFactory
```

The candidate query resolves only the minimum relationship IDs needed for identity checks. Full workflow/order status data is loaded only after the relevant access decision succeeds.

## Email sources recognized by the backend

The identity resolver can recognize these **order-scoped** sources without returning any email value:

- `client`: the Order's client primary email.
- `client_contact`: a structured contact belonging to the Order's client.
- `billing_contact`: an invoice billing email belonging to the Order.
- `order_user`: an active FlowTrack user directly tied to the Order as owner, coordinator, creator, explicit Order member, or task assignee.
- `supplier`: an active supplier directly assigned to the Order or to an active Order line.

Supplier resolution deliberately does **not** traverse RFQ/inquiry supplier contacts, unrelated product supplier catalog links, or other records merely because an email happens to match.

### End-customer email schema gap

The current schema stores `end_customer` / `other_contact` delivery contacts with name and phone, but **does not store an email address** for those delivery contacts. No guessed join or activity metadata is used to manufacture that association. If end-customer email must authorize tracking, its canonical persistence/source must be decided before adding it.

## Disclosure rule in this phase

Recognizing an identity is not the same as authorizing the same public payload for every audience.

For Phase 1 the existing live result is still disclosed only to:

- client primary email,
- structured client contact email,
- invoice billing contact email.

Order-linked users and suppliers are recognized by the backend but are **not yet granted the customer result**. This prevents accidentally bridging billing/payment/customer workflow information before the required audience rules are specified.

## Public-safe snapshot

`PublicOrderTrackingSnapshot` is detached from Eloquent and carries only fields needed for future stage/status derivation. It intentionally excludes:

- all email addresses,
- names/profile data for client, staff, supplier, or contacts,
- internal database IDs,
- phone/address data,
- notes/comments,
- documents/files,
- supplier/client commercial profile data,
- invoice totals,
- payment amounts.

Payment amounts are used only inside the backend snapshot factory to derive `none / partial / paid`; the amounts themselves do not cross the boundary.

## Query/performance behavior

- Candidate lookup is bounded to a single active Order by order/reference identifier.
- Identity checks are `exists()`/bounded linked-record reads scoped by that Order/client.
- No full Order relations are loaded before access succeeds.
- Workflow tasks and activities are batch eager-loaded.
- Shipment data loads only from Stage 5 onward (or completed Orders).
- Invoice data loads only from Stage 6 onward (or completed Orders).
- Payment data loads only from Stage 7 onward (or completed Orders).
- No per-task, per-shipment, per-invoice, or per-payment loop query is introduced.
- The existing presenter remains query-free.

## Security behavior retained

- Route remains public without login by design.
- POST remains under Laravel web CSRF protection.
- Generic failed-lookup response is preserved; order/email existence is not disclosed.
- Existing GET/POST throttles remain in place.
- Tracking responses remain no-store/no-cache, noindex/nofollow, frame denied, MIME-sniff protected, and no-referrer.
- Cancelled/soft-deleted Orders are not returned.
- Inactive FlowTrack users and inactive/deleted suppliers do not qualify as linked identities.
- Removed Order lines do not qualify a supplier email.

## Next phase

Before broadening public access, define what each recognized audience (`client`, `client_contact`, `billing_contact`, `order_user`, `supplier`, and any future end-customer identity) is allowed to see. The status presenter can then consume `PublicOrderTrackingSnapshot` instead of the compatibility `FlowJob` bridge.
