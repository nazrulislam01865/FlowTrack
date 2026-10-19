# Sidebar navigation flash fix — 2026-08-27

## Root cause

The authenticated layout uses Livewire `wire:navigate`. Livewire performs SPA-style navigation by replacing the page body. The sidebar lived inside that body without a persistence boundary, so every sidebar click destroyed and recreated the complete sidebar DOM. The dark sidebar, logo, expanded `<details>` groups, active classes, counters, and scroll container were all repainted. That repaint was the visible flash.

## Permanent fix

- Wrapped the sidebar in Livewire 4 `@persist('flowtrack-sidebar')`, so the same sidebar DOM is reused across `wire:navigate` visits.
- Added `wire:navigate:scroll` to the scrollable sidebar navigation area so the user's sidebar scroll position is preserved.
- Added route metadata to the shared sidebar link component.
- Added a small persisted-navigation synchronizer that updates only active link/group state after navigation. It is query-aware for shared routes such as Orders/Create Order, Inquiries/Create Inquiry, Clients/Add Client, Product/Create Product, Product Categories, Supplier, and Administration tabs.
- Kept mobile sidebar open/close behavior unchanged; the existing shell initializer continues to bind the replaced mobile menu and shade safely.
- Moved the Cancelled Orders sidebar count into the cached shell payload and exposed it through the existing background shell endpoint, preventing the persisted badge from becoming stale.
- Included the sidebar navigation helper in the frontend build fingerprint so long-open tabs reload once after this deployment, then return to SPA navigation normally.

## Scope

No order, inquiry, product, client, workflow, permission, cancellation, task, or form business logic was changed. The change is limited to the application shell/navigation state and sidebar counters.
