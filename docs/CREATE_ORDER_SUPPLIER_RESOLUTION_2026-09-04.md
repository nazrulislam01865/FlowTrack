# Create Order missing Supplier resolution

Date: 2026-09-04

## Goal

When a Product selected in Create Order has no active default Supplier, keep the user in the Products & quantities flow and present the approved `Supplier not linked` modal instead of forcing a separate Master Data workflow.

## User flow

The modal keeps the supplied design and offers three explicit paths:

1. **Link existing supplier** - uses the shared remote Supplier search and links the selected active Supplier to Product Master before adding the Product row.
2. **Create new supplier** - default choice; creates an active Supplier with Supplier name and optional Email, links it to Product Master, then adds the Product row.
3. **Continue without supplier** - adds the Product to this Order draft with a null Supplier and preserves the existing explicit skip behavior. A Supplier can still be assigned later from Order Details.

The same modal is reused if final Create Order validation discovers a selected Product whose Supplier is still missing.

## Architecture

- Livewire remains the workflow coordinator in `ManagesCreateOrderProducts`.
- Persistent writes are isolated in `LinkCreateOrderProductSupplier` and `CreateAndLinkCreateOrderProductSupplier` Actions.
- Product/Supplier reads and normalized Product-Supplier persistence continue through the existing `ProductCatalogService` and `MasterDataService` boundaries.
- Product resolution is row-locked before a persistent link/create action, so concurrent requests do not overwrite an already-resolved Product default Supplier.
- The Order item payload contract is unchanged; the existing draft-only skip list stays outside `jobItems`.
- No database migration is required.

## UI system

The modal composes existing `x-ui.modal`, `x-ui.search-select`, `x-ui.input`, `x-ui.validation-message`, and `x-ui.button` components. Feature layout lives in `resources/css/modules/orders/create-order-supplier-resolution.css` and consumes centralized `--ft-*` typography, color, spacing, radius, control-size, focus, and motion tokens. No feature-specific hard-coded colors or `!important` declarations were introduced.

## Authorization

- Viewing/adding Create Order products still requires `jobs.create` and `catalog_products.view`.
- Persistently linking an existing Supplier to Product Master additionally requires `catalog_products.edit`.
- Creating a new Supplier additionally requires `suppliers.create`.
- Continue-without-Supplier retains the existing Create Order product authorization only.
