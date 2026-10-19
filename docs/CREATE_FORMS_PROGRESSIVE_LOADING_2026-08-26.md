# Create Forms Progressive Loading

Date: 2026-08-26

## Goal

Create and editor pages must become usable immediately. Expensive reference datasets that belong to lower sections should not be queried on the first render. They hydrate only when the user approaches the section, following the established Create Order pattern.

This deliberately does **not** defer the entire Livewire component. Whole-page defer/lazy loading previously broke route-sensitive Create and Details pages because their mount state depends on the original URL.

## Shared pattern

`resources/views/components/ui/progressive-section-loader.blade.php` is the reusable viewport trigger. It uses `IntersectionObserver` with a 240px prefetch margin, renders the existing FlowTrack skeleton, disconnects after the first trigger, and falls back to immediate hydration in browsers without IntersectionObserver.

Each Livewire form keeps its own small `loadCreateSection()` method because the server-side dependencies are domain-specific. Readiness booleans guard the corresponding queries. This avoids a large generic component with business logic mixed across modules.

## Coverage

### Create Order

Existing reference implementation retained.

- Product catalogue: progressive.
- Schedule / owner reference data: progressive.
- Workflow and Task Pack preview: progressive.
- Basic order/client fields: immediate.
- Attachments: local until the user selects a file.

### Create Inquiry

- Product catalogue: progressive (existing boundary retained).
- Workflow options / workflow task counts: now progressive.
- Workflow is resolved just-in-time if the user submits with the keyboard before the section reaches the viewport.
- Client, contact, owner, priority and basic request data remain immediate because they are needed in the first visible section.

### Create Client

- Shipping and billing country/state datasets: now progressive.
- Country/state queries do not run until the user approaches the address sections.
- Account manager, currency and basic client fields stay immediate because they are in the first visible section.
- Edit Client remains immediate for existing address values to avoid hiding already-saved data.

### Create Product

- Full product list no longer hydrates behind the standalone Create Product editor.
- Product taxonomy / category hierarchy: now progressive.
- Shipping urgency master data: now progressive.
- Pricing, options, availability and local inputs stay immediate where they do not require the heavy reference datasets.
- Edit Product keeps taxonomy and existing urgency data immediate so stored values are visible on entry.

### Add Product Category

This is a compact single-step editor rather than a long multi-section form. It does not use a fake skeleton. On a direct Add Category route, only the parent records required by that editor are queried; the full category hierarchy/counts remain unloaded.

### Create Workflow

- "Start from" workflow template list: now progressive.
- A route explicitly opened from a source workflow (`?source=`) keeps source options immediately available because that source context is part of navigation intent.
- Specific-client options were already remote/bounded and remain user-driven.

### Create Task Pack

- Assignee, department, priority, duration/timer, document category and calendar reference options no longer start loading immediately with `wire:init`.
- They hydrate when the first task option area approaches the viewport.
- Submitting before that point performs a bounded just-in-time hydration and continues instead of showing a false "wait for options" error.

### Add User

Add User is a compact modal, so a viewport skeleton would add no value. Role and department queries are now on-demand: they run only while Add/Edit User is actually open, not on every Administration > Users list render.

### Other compact create/setup modals

Small one-step editors (for example Role and small Master Data records) are intentionally not given cosmetic lazy-loading placeholders. Their datasets are either local/static or already fetched only after the modal is opened. Progressive loading is used only where it removes meaningful server work without making the form harder to use.

## Server-load rules

1. Never defer an entire Create/Edit/Details page merely to make it look lazy.
2. Hydrate below-the-fold reference datasets near the viewport.
3. Keep first-screen fields immediately interactive.
4. Use bounded/remote search for large dropdowns instead of loading all records.
5. Avoid running list/count queries behind a full-page editor.
6. Preserve existing values immediately on Edit pages.
7. Add a just-in-time server fallback when a valid submit can occur before a progressive section becomes visible.
8. Do not introduce a skeleton when there is no expensive query to defer.

## Quality guard

`scripts/quality/progressive-page-loading.php` now checks the create-form boundaries as well as route-sensitive page mounting. Run:

```bash
npm run quality:page-loading
```

The check covers Create Inquiry, Create Client, Create Product, Create Workflow, Create Task Pack, Add User on-demand references, the reusable progressive loader, and the existing page-level navigation protections.

## Build note

This change does not require new CSS or JavaScript source. The reusable loader uses the existing `.ft-progressive-section-placeholder` and `.ft-progressive-skeleton` styles, so no Vite rebuild is required for this update.
