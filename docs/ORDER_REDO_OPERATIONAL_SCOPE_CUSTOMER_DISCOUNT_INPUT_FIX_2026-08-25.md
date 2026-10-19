# FlowTrack Redo - Artwork/Production Customer Discount Input Fix

## Problem
The `Discount instead of redo` scope already used a manual percentage input, but the two operational scopes (`Artwork + production redo` and `Production-only redo`) still rendered the old fixed customer-discount `<select>` when Customer resolution was changed to Discount.

A second issue was a stale compiled Blade file under `storage/framework/views`, which could continue rendering the old dropdown even after the source Blade was changed.

## 1. View change
File:
`resources/views/components/jobs/order-detail/redo-modal.blade.php`

Inside the non-discount-scope branch, replace the old customer discount select:

```blade
@if($redoCustomerResolution === 'discount')
    <label class="ft-redo-field">
        <span>Customer discount</span>
        <select wire:model.live="redoCustomerDiscount">
            @foreach([10,15,20,30,40] as $pct)
                <option value="{{ $pct }}">{{ $pct }}%</option>
            @endforeach
        </select>
        @error('redoCustomerDiscount')
            <small class="validation-error">{{ $message }}</small>
        @enderror
    </label>
@endif
```

with:

```blade
@if($redoCustomerResolution === 'discount')
    <label class="ft-redo-field" wire:key="redo-{{ $redoScope }}-customer-discount">
        <span>Customer discount *</span>

        <div class="ft-redo-percent-input">
            <input
                type="number"
                min="0"
                max="100"
                step="0.01"
                inputmode="decimal"
                wire:model.live.debounce.250ms="redoCustomerDiscount"
                placeholder="Enter discount"
                aria-label="Customer discount percentage"
            >

            <span class="ft-redo-percent-suffix" aria-hidden="true">%</span>
        </div>

        @error('redoCustomerDiscount')
            <small class="validation-error">{{ $message }}</small>
        @enderror
    </label>
@endif
```

The modal now has exactly two manual customer-discount inputs in source:
- one for the standalone Discount scope;
- one shared by Artwork/Production when Customer resolution = Discount.

There is no `select wire:model.live="redoCustomerDiscount"` left in the source view.

## 2. Decimal-safe display values
In the same Blade file, change:

```php
$redoCustomerDiscount = (int) ($form['customerDiscount'] ?? 20);
$redoSupplierChargePercent = (int) ($form['supplierChargePercent'] ?? 40);
```

to:

```php
$redoCustomerDiscount = (string) ($form['customerDiscount'] ?? '20');
$redoSupplierChargePercent = (string) ($form['supplierChargePercent'] ?? '40');
```

This prevents values such as `12.5` from being truncated in the confirmation text.

## 3. Livewire scope reset correction
File:
`app/Livewire/Jobs/Concerns/ManagesOrderRedo.php`

Change the operational-scope reset comparison from a strict integer comparison to a numeric comparison:

```php
if ((float) $this->redoSupplierChargePercent === 0.0
    && !$this->redoDeductFreight
    && (float) $this->redoFreightAmount === 0.0
    && $this->redoSupplierId === null
    && $this->redoCustomerResolution === 'discount') {
```

The property is a string because it is user-editable, so comparing it to integer `0` with `===` does not work.

Also pass the customer discount to the service as a float:

```php
'customer_discount_percent' => (float) $this->redoCustomerDiscount,
```

## 4. Stale compiled Blade cache
The uploaded project contained a compiled Blade view under:

`storage/framework/views/`

that still contained the old customer-discount dropdown. Those compiled `.php` files have been removed from the full updated package.

After manually copying changed files into an existing installation, run:

```bash
php artisan view:clear
php artisan optimize:clear
```

This is required if the browser still displays the old select after the Blade source has been replaced.

## Final behavior
### Artwork + production redo
- Customer resolution = Free redo -> no customer discount field.
- Customer resolution = Discount instead of redo -> manual customer discount percentage input.

### Production-only redo
- Customer resolution = Free redo -> no customer discount field.
- Customer resolution = Discount instead of redo -> manual customer discount percentage input.

### Discount instead of redo
- Manual customer discount percentage input is always shown.

All three flows use the same `redoCustomerDiscount` Livewire value and the same live financial-preview calculation.
