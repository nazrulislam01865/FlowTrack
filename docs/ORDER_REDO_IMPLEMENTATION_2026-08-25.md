# FlowTrack Order Redo Implementation

Date: 2026-08-25

## Source of truth

The feature was implemented against `flowtrack-order-redo-prototype.html` and integrated into the existing Laravel/Livewire Order Details flow without replacing the existing task, workflow, invoice, payment, product, or permission systems.

## User experience implemented

1. Order Details header shows **↻ Initiate Redo** only when the current user can edit the visible Order and has Order create permission.
2. The Redo modal follows the prototype's four steps exactly:
   - Issue
   - Scope
   - Commercial
   - Confirm
3. Issue step stores reporter, category, local reported date, affected quantity, description, and displays existing source-task evidence.
4. Scope step supports:
   - Artwork + production redo
   - Production-only redo
   - redo quantity
   - responsible supplier
   - internal instructions
5. Commercial step supports:
   - Free redo
   - Discount instead of redo
   - customer discount percent
   - supplier redo charge percent
   - optional freight deduction and amount
   - live financial preview
6. Confirm step shows the new `-R#` Order number, linkage, issue, workflow restart, customer resolution and supplier recovery before creation.
7. After creation, Order Details shows:
   - red **↻ Redo order** tag
   - redo banner
   - Redo tab/count
   - original → redo relationship card
   - financial impact
   - redo audit trail
8. The Invoices & Payments tab also shows the Redo financial adjustment review while leaving the original invoice and payment rows untouched.

## Persistence and workflow behavior

A new `order_redos` table stores the immutable relationship/commercial snapshot for each redo.

Creating a Redo:

- never overwrites the original Order;
- never rewinds or mutates the original Order's taskflow;
- never changes original invoices or payments;
- creates a separate linked FlowJob using `ORDER-...-R1`, `-R2`, etc.;
- clones the required quantity from active Order product lines;
- carries supplier/order ownership, shipping data and relevant Order metadata;
- uses the currently published Order workflow;
- starts at Artwork for `artwork` scope or Production for `production` scope;
- generates tasks through the existing `JobService::syncWorkflowTasks()` path;
- marks earlier workflow phases as already passed through the existing Order task generation rules;
- continues using existing sequential task activation/phase advancement logic;
- creates activity/audit entries on both the original Order and redo Order.

## Main files added

- `app/Models/OrderRedo.php`
- `app/Services/OrderRedoService.php`
- `app/Livewire/Jobs/Concerns/ManagesOrderRedo.php`
- `database/migrations/2026_08_25_161500_create_order_redos_table.php`
- `resources/views/components/jobs/order-detail/redo-banner.blade.php`
- `resources/views/components/jobs/order-detail/redo-panel.blade.php`
- `resources/views/components/jobs/order-detail/redo-modal.blade.php`
- `resources/views/components/jobs/order-detail/redo-finance.blade.php`
- `resources/css/modules/orders/detail/redo.css`
- `tests/Feature/OrderRedoImplementationTest.php`

## Existing files updated

- `app/Models/FlowJob.php`
  - adds original/redo relationships.
- `app/Livewire/Jobs/Index.php`
  - mixes in Redo Livewire behavior and supports the Redo tab.
- `app/Livewire/Jobs/Concerns/ManagesOrderNavigation.php`
  - adds Redo tab navigation and modal cleanup.
- `app/Livewire/Jobs/Concerns/BuildsOrderPageData.php`
  - supplies Redo relationship and form state to Order Details.
- `app/Services/LegacyJobService.php`
  - permits Redo as a lightweight Order detail tab.
- `resources/views/components/jobs/detail.blade.php`
  - mounts the Redo banner, tab panel, modal and notification toast.
- `resources/views/components/jobs/order-detail/header.blade.php`
  - adds Initiate Redo and Redo tag.
- `resources/views/components/jobs/order-detail/tabs.blade.php`
  - adds Redo tab/count.
- `resources/views/components/jobs/finance/detail.blade.php`
  - shows the Redo adjustment review.
- `resources/css/modules/orders/detail-prototype.css`
  - imports Redo CSS.
- `public/build/manifest.json`
  - points the Order CSS entry to the included rebuilt/combined Redo-aware CSS asset.
- `public/build/assets/index-cc2db1c0.css`
  - deploy-ready Order CSS containing the Redo styles.

## Deployment

Run from the application root after replacing the files:

```bash
php artisan migrate --force
php artisan optimize:clear
```

The updated ZIP already includes the deploy-ready compiled Order CSS asset. If you normally rebuild Vite assets on the target deployment pipeline, rebuild normally afterward:

```bash
npm ci
npm run build
```

## Verification completed in this package

- PHP syntax checks passed for all modified/new PHP files.
- A static feature contract test is included at `tests/Feature/OrderRedoImplementationTest.php`.
- The supplied project archive does not include `vendor/`, so the complete Laravel/PHPUnit runtime suite could not be executed in this workspace.
