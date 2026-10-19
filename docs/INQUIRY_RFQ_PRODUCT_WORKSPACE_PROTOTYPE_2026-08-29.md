# Inquiry Details RFQ product workspace — 2026-08-29

## Scope

Redesigned **Inquiry Details → RFQ** to follow the supplied Product RFQ invitations prototype without changing the surrounding Inquiry Details navigation or Comparison workflow.

## Structure

The RFQ view is now product-first:

- Prototype header and **Assign suppliers** action.
- Four reusable RFQ summary cards: products, supplier assignments, invitations sent, quotations received.
- Product/supplier search, invitation-status filter, and existing email-settings action.
- Product-scoped bulk selection bar.
- Reusable product groups with supplier assignment counts, invitation progress, quotation counts, failures, per-product actions, and expandable supplier tables.
- Per-supplier send/retry/resend/setup-email actions.
- Product pagination/footer matching the prototype hierarchy.

## Reusable components

- `resources/views/components/inquiries/rfq-product-workspace.blade.php`
- `resources/views/components/inquiries/rfq-product-group.blade.php`
- `resources/views/components/inquiries/rfq-product-supplier-row.blade.php`
- Existing `resources/views/components/inquiries/product-rfq-stat.blade.php` is reused for the summary cards.
- `app/Support/InquiryRfqProductWorkspacePresenter.php` owns the stable query-free presentation contract.

## Supplier assignment behavior

The global **Assign suppliers** action asks for a product first. The per-product **Add supplier** action opens the same reusable modal already scoped to that product. Supplier assignment synchronizes Product Master metadata and the normalized `product_supplier_links` table through `ProductCatalogService::assignSupplierToProduct()`.

Existing RFQ invitations are reused when the same supplier is assigned to another product. A new draft invitation is created only when that supplier is not already an Inquiry RFQ participant.

## Compatibility

The existing Inquiry RFQ persistence model remains intact. The product-first UI projects supplier assignment and quote-item coverage onto each Inquiry product while reusing the existing secure invitation delivery flow. Existing Comparison, quotation submission, email preview, award, and supplier-email setup flows are preserved.

## Styling

The prototype styling is isolated in:

`resources/css/modules/application/24-inquiry-rfq-product-workspace.css`

It is imported after the earlier Inquiry RFQ modules so the new RFQ layout does not alter the Overview redesign or other application pages. The current compiled application CSS was refreshed with the same module for installations using the bundled `public/build` assets.
