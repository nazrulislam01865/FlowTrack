# FlowTrack Order Redo - Discount Instead of Redo Scope

Date: 2026-08-25

## Requested behavior

Step 2 of **Initiate Redo** now has a third scope:

- Artwork + production redo
- Production-only redo
- **Discount (instead of redo)**

When **Discount** is selected, FlowTrack does **not** create a replacement Order, does **not** restart Artwork/Production, and does **not** generate redo tasks. The original Order stays in its current phase. The affected quantity is used to calculate the client discount and the financial adjustment is stored in `order_redos` for the Redo/Finance views and audit trail.

## Files changed

### 1. `resources/views/components/jobs/order-detail/redo-modal.blade.php`

Adds the third scope card and the Discount-specific UX.

Key option:

```blade
<label class="ft-redo-choice {{ $redoScope === 'discount' ? 'selected' : '' }}">
    <input type="radio" value="discount" wire:model.live="redoScope">
    <div>
        <b>Discount (instead of redo)</b>
        <small>Do not restart any workflow phase. Give a discount to the client and record the financial adjustment only.</small>
    </div>
</label>
```

For Discount, Step 2 hides redo quantity/supplier restart inputs and explains that no workflow is restarted. Step 3 forces the customer resolution to Discount and exposes the discount percentage. Step 4 changes the action to **Record discount** and clearly states that no redo Order/tasks are created.

### 2. `app/Livewire/Jobs/Concerns/ManagesOrderRedo.php`

`redoScope` validation now accepts `discount`:

```php
'redoScope' => ['required', Rule::in(['artwork', 'production', 'discount'])],
```

The Livewire update hook sets safe Discount defaults:

```php
public function updatedRedoScope($value): void
{
    if ((string) $value === 'discount') {
        $this->redoCustomerResolution = 'discount';
        $this->redoQuantity = (string) max(1, (int) $this->redoAffectedQuantity);
        $this->redoSupplierId = null;
        $this->redoSupplierChargePercent = 0;
        $this->redoDeductFreight = false;
        $this->redoFreightAmount = '0.00';
        return;
    }

    // Restore normal redo defaults when changing back from Discount.
    if ($this->redoSupplierChargePercent === 0
        && !$this->redoDeductFreight
        && (float) $this->redoFreightAmount === 0.0
        && $this->redoSupplierId === null
        && $this->redoCustomerResolution === 'discount') {
        $this->redoCustomerResolution = 'free';
        $this->redoSupplierChargePercent = 40;
        $this->redoDeductFreight = true;
        $this->redoFreightAmount = '320.00';
        $this->redoSupplierId = $this->redoSupplierOptions[0]['id'] ?? null;
    }
}
```

After saving a Discount scope, FlowTrack remains on the original Order and opens the Redo tab instead of attempting to open a replacement Order.

### 3. `app/Services/OrderRedoService.php`

The service now branches before workflow creation:

```php
if ($scope === 'discount') {
    return $this->createDiscountAdjustment(
        $root,
        $data,
        $actor,
        $sequence,
        $redoQuantity,
        $supplierId,
        $preview,
    );
}
```

The Discount branch creates only an `OrderRedo` audit/financial record:

```php
'redo_order_id' => null,
'scope' => 'discount',
'customer_resolution' => 'discount',
```

It does **not** call:

- `FlowJob::create()` for a replacement Order
- `JobService::syncWorkflowTasks()`
- `initializeRedoWorkflowAtPhase()`

The original workflow and tasks therefore remain unchanged.

Actual redo Order numbering is also independent from Discount records, so recording a Discount first does not consume `-R1`.

### 4. New migration

`database/migrations/2026_08_25_170500_make_order_redo_order_nullable_for_discount_scope.php`

Makes `order_redos.redo_order_id` nullable because a Discount resolution has no replacement `flow_jobs` row.

Run:

```bash
php artisan migrate
php artisan optimize:clear
```

Production:

```bash
php artisan migrate --force
php artisan optimize:clear
```

### 5. Presentation updates

Updated:

- `resources/views/components/jobs/order-detail/redo-banner.blade.php`
- `resources/views/components/jobs/order-detail/redo-panel.blade.php`
- `resources/views/components/jobs/order-detail/redo-finance.blade.php`

Discount records now display as a **Customer discount adjustment**, show **No workflow restart**, and never render a broken link to a nonexistent redo Order.

### 6. Styling

Updated:

- `resources/css/modules/orders/detail/redo.css`
- current built Order CSS in `public/build/assets/...`

The scope cards use three columns on desktop and one column on mobile. Discount-specific notice/fixed-resolution cards match the existing Redo modal visual language.

## Final behavior

### Artwork + production redo

Creates `ORDER-...-R#` and starts from Artwork.

### Production-only redo

Creates `ORDER-...-R#` and starts from Production.

### Discount (instead of redo)

- No new Order
- No phase restart
- No redo tasks
- Original Order remains at its current stage/status
- Customer discount is calculated from the affected quantity/value
- Financial adjustment appears in Redo and Invoices & Payments views
- Audit activity is recorded on the original Order
- Future actual redo numbering still starts/continues with the correct `-R#`
