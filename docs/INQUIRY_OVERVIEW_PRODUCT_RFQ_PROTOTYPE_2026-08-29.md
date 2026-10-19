# Inquiry Overview Product / RFQ Prototype — 2026-08-29

## Scope

Redesigns only **Inquiry Details → Overview → Products & quantities** to match the supplied prototype. Order Details and Create Inquiry product layouts are intentionally unchanged.

## UI structure

The section is now a reusable Inquiry component set:

- `x-inquiries.product-rfq-overview`
- `x-inquiries.product-rfq-overview-row`
- `x-inquiries.product-rfq-stat`

The resting state follows the prototype structure:

1. Four summary cards: products, supplier assignments, invitations sent, quotations received.
2. One `Products, suppliers & RFQ progress` card.
3. Header-level `Add product` action and product/unit summary.
4. Product table with Product, Quantity, Assigned suppliers, RFQ progress, Quotations, Updated, and actions.
5. Informational footer strip.

Existing Add Product, Edit Product, Remove Product, and RFQ navigation actions are preserved.

## Data architecture

No database schema was changed.

- `ProductCatalogService::allSuppliersForProducts()` resolves all active Product → Supplier assignments in a bounded read, preferring `product_supplier_links` and retaining metadata fallback compatibility.
- `InquiryRfqService::overviewInvitations()` loads only the RFQ fields and quote-item links needed by the Overview section.
- `InquiryProductRfqOverviewPresenter` is query-free and converts the loaded models into the stable component view contract.
- `BuildsInquiryPageData` prepares the complete view model before Blade rendering, preserving the project's existing read-side architecture.

Because the current RFQ invitation schema is Inquiry-level, Overview maps invitation delivery to the Product's assigned Supplier and maps submitted quotations to `inquiry_item_id` through RFQ quote items. No RFQ persistence behavior was changed by this redesign.

## Styling

Prototype-only styles live in:

`resources/css/modules/application/23-inquiry-product-rfq-overview.css`

The module is imported by the normal application CSS composition root. The prebuilt `resources/css/app.css` Vite asset in `public/build/manifest.json` was also refreshed so the redesign is visible without requiring an immediate local asset rebuild.

## Verification

- PHP syntax checked for all changed PHP/test files.
- New CSS source and the refreshed prebuilt CSS bundle have balanced block braces.
- Vite manifest resolves the refreshed application CSS asset.
- Existing Inquiry static tests that referenced the old shared products card were updated to the new Inquiry-specific reusable component.
- The Phase 6 and CSS governance scripts were run; they still report pre-existing repository baseline/protected-boundary failures unrelated to this change.
