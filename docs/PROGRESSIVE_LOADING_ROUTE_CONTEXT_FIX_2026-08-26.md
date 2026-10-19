# Progressive Loading Route-Context Fix — 2026-08-26

## Problem

The first progressive-loading pass deferred almost every page-level Livewire component. That is safe only when the component does not need the original page request during `mount()`.

Several FlowTrack workspaces use one Livewire component for multiple URL-driven modes:

- Orders: list / create / order details / task deep-link
- Inquiries: list / create / inquiry details / task deep-link
- Clients: list / create
- Master Data: list / product create / product details / product-category create
- Documents: normal list / client-filtered / order-upload context
- Workflow Setup: default selection / `?workflow=` deep-link
- Administration: `?tab=` selection
- User Editor: `?from=administration` return context

When those components were deferred, their `mount()` method ran later in a Livewire update request instead of during the original GET. Code such as `request()->boolean('create')`, `request()->integer('open')`, and `request()->query('tab')` therefore no longer saw the original route query string. The component fell back to its default list state, so Create and Details links appeared not to work.

## Correct loading policy

FlowTrack now uses three loading boundaries instead of deferring every root component.

### 1. Route-sensitive pages mount immediately

Create, Details, editor, upload-context, and deep-linked pages mount on the original request so routing state is deterministic.

Examples:

- `resources/views/pages/jobs.blade.php`
- `resources/views/pages/inquiries.blade.php`
- `resources/views/pages/clients.blade.php`
- `resources/views/pages/master-data.blade.php`
- `resources/views/pages/workflow-form.blade.php`
- `resources/views/pages/task-pack-form.blade.php`
- `resources/views/pages/user-edit.blade.php`
- `resources/views/pages/administration.blade.php`
- `resources/views/pages/documents.blade.php`

### 2. Plain read/list mode may still defer

List pages that have no initial route context continue to load after the application shell.

Examples include Dashboard, All Tasks, Cancelled Orders, My Task, Notifications and Reports. Orders, Inquiries, Clients, Documents and Workflow Setup also defer only when opened in their plain list mode.

### 3. Heavy inner sections remain genuinely progressive

The server-load savings stay at the query boundary rather than only delaying an entire page:

- Product / Product Category list records use internal `wire:init` loading.
- Product Category descendants are loaded in bounded batches only when expanded.
- Create Order heavy sections use viewport-triggered section loading.
- Create Inquiry product catalogue queries run only when the product section becomes relevant.
- Dashboard tagged comments remain viewport-lazy.
- Task Pack list records use internal `wire:init` loading.

This preserves navigation correctness while still preventing expensive data that is not needed yet from being queried immediately.

## Files changed in this fix

- `resources/views/pages/jobs.blade.php`
- `resources/views/pages/inquiries.blade.php`
- `resources/views/pages/clients.blade.php`
- `resources/views/pages/documents.blade.php`
- `resources/views/pages/workflow-setup.blade.php`
- `resources/views/pages/master-data.blade.php`
- `resources/views/pages/task-pack-setup.blade.php`
- `resources/views/pages/workflow-form.blade.php`
- `resources/views/pages/task-pack-form.blade.php`
- `resources/views/pages/user-edit.blade.php`
- `resources/views/pages/profile.blade.php`
- `resources/views/pages/company-setup.blade.php`
- `resources/views/pages/order-workflow-setup.blade.php`
- `resources/views/pages/administration.blade.php`
- `config/livewire.php`
- `scripts/quality/progressive-page-loading.php`

## Regression guard

Run:

```bash
npm run quality:page-loading
```

The policy now fails if a route-sensitive/editor component is globally deferred, while still requiring safe list components and the existing inner lazy-loading boundaries to remain progressive.

## Packaging correction

The prior generated Saving Feedback ZIP did not contain the `app/` directory. The corrected full ZIP is built from the complete progressive-loading project tree and includes `app/` plus the later saving-feedback changes.
