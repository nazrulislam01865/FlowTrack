# Create Order quick-product UX update — 2026-08-29

## Changes
- Removed the read-only Order Title field from the Create Order frontend. Order titles continue to be generated server-side from the client reference and selected products.
- Replaced the small no-results “Create new product” button with a larger contextual CTA that carries the searched product name and explains the action.
- Made SKU / Product code optional in the Create Order quick-create modal. Blank values now use `MasterDataService::nextCode('product')`; manually entered values retain format and uniqueness checks.
- Made Default supplier optional in the same modal. Products can be created without supplier metadata and are added to the current Order with an explicit supplier skip so the user is not bounced into a second confirmation modal.
- Product image availability now depends on required product details (category and name), not on an optional supplier.
