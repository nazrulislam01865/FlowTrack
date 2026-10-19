@props([
    'productId',
    'isActive' => true,
    'canEdit' => false,
    'canDelete' => false,
])

@php
    $actionCount = 1 + ($canEdit ? 3 : 0) + ($canDelete ? 1 : 0);
    $estimatedMenuHeight = 10 + ($actionCount * 33) + ($canDelete ? 3 : 0);
    $menuId = 'product-actions-'.$productId;
@endphp

<div
    class="ft-product-action-menu"
    x-data="{ open: false, busy: false }"
    x-on:scroll.window="if (open && $refs.menu.matches(':popover-open')) $refs.menu.hidePopover()"
    x-on:resize.window="if (open && $refs.menu.matches(':popover-open')) $refs.menu.hidePopover()"
>
    <button
        type="button"
        class="ft-product-action-trigger"
        :aria-expanded="open ? 'true' : 'false'"
        aria-haspopup="menu"
        aria-controls="{{ $menuId }}"
        aria-label="Product actions"
        x-on:click.stop="
            const menu = $refs.menu;
            if (menu.matches(':popover-open')) {
                menu.hidePopover();
                return;
            }

            const rect = $el.getBoundingClientRect();
            const menuWidth = 148;
            const menuHeight = {{ $estimatedMenuHeight }};
            const edge = 10;
            const gap = 6;
            const availableBelow = window.innerHeight - rect.bottom;
            const availableAbove = rect.top;
            const openAbove = availableBelow < (menuHeight + gap + edge) && availableAbove > availableBelow;
            const left = Math.min(
                window.innerWidth - menuWidth - edge,
                Math.max(edge, rect.right - menuWidth)
            );
            const desiredTop = openAbove
                ? rect.top - menuHeight - gap
                : rect.bottom + gap;
            const top = Math.min(
                window.innerHeight - menuHeight - edge,
                Math.max(edge, desiredTop)
            );

            menu.style.left = `${left}px`;
            menu.style.top = `${top}px`;
            menu.showPopover();
        "
    >
        <span></span><span></span><span></span>
    </button>

    <div
        id="{{ $menuId }}"
        class="ft-product-action-panel"
        x-ref="menu"
        popover="auto"
        role="menu"
        x-on:toggle="open = $event.newState === 'open'"
    >
        <button
            type="button"
            role="menuitem"
            x-on:click="$refs.menu.hidePopover(); $wire.viewProduct({{ $productId }})"
        >View product</button>

        @if($canEdit)
            <button
                type="button"
                role="menuitem"
                x-on:click="$refs.menu.hidePopover(); $wire.editProduct({{ $productId }})"
            >Edit product</button>
            <button
                type="button"
                role="menuitem"
                x-on:click="$refs.menu.hidePopover(); $wire.openProductSupplierAssignmentFor({{ $productId }})"
            >Assign supplier</button>
            <button
                type="button"
                role="menuitem"
                x-bind:disabled="busy"
                x-on:click="
                    if (busy) return;
                    busy = true;
                    $refs.menu.hidePopover();
                    $wire.toggleProductStatus({{ $productId }}).finally(() => busy = false);
                "
            >{{ $isActive ? 'Deactivate' : 'Activate' }}</button>
        @endif

        @if($canDelete)
            <button
                type="button"
                role="menuitem"
                class="is-danger"
                x-bind:disabled="busy"
                x-on:click="
                    if (busy || !window.confirm('Delete this product?')) return;
                    busy = true;
                    $refs.menu.hidePopover();
                    $wire.deleteProduct({{ $productId }}).finally(() => busy = false);
                "
            >Delete product</button>
        @endif
    </div>
</div>
