# Order Details - Redo Initiated Badge Fix

## Changed files

1. `resources/views/components/jobs/order-detail/header.blade.php`
2. `resources/css/modules/orders/detail/redo.css`

## Header logic

The header now distinguishes:

- `hasRedo`: any Redo/Discount record exists.
- `isRedoOrder`: the currently displayed order is an actual replacement Redo Order.
- `redoOrderCount`: number of actual linked Redo Orders created from the root Order.
- `redoInitiated`: true only when an operational Redo Order exists.

```php
$isRedoOrder = (bool) ($redoContext['isRedoOrder'] ?? false);
$redoOrderCount = (int) ($redoContext['redoOrderCount'] ?? 0);
$redoInitiated = $isRedoOrder || $redoOrderCount > 0;
```

The `Redo initiated` pill is rendered before the normal Order pills:

```blade
@if($redoInitiated)
    <span class="pill redo-initiated" title="A linked Redo Order has been initiated.">
        Redo initiated
    </span>
@endif
```

The current stage now uses the existing `$stageName` value instead of the hard-coded `New Order` text:

```blade
<span class="pill purple" id="stagePill">{{ $stageName }}</span>
```

The `↻ Redo order` pill is now limited to the actual replacement Redo Order:

```blade
@if($isRedoOrder)
    <span class="pill redo">↻ Redo order</span>
@endif
```

This prevents an original Order from being incorrectly labelled as a Redo Order just because it has a child Redo.

## CSS

```css
.ft-order-prototype-detail .pill.redo-initiated {
    background: var(--redo-red-bg);
    color: var(--redo-red);
    border: 1px solid transparent;
    font-weight: 850;
}
```

## Behaviour

- Original Order before actual Redo: normal status/stage pills only.
- Original Order after actual Redo: `Redo initiated` is shown.
- Replacement `-R1/-R2` Order: `Redo initiated` and `↻ Redo order` are shown.
- Discount-only resolution: does **not** show `Redo initiated` because no replacement Redo Order exists.

No migration is required.
