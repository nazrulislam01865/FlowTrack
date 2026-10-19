@props([
    'itemId' => null,
    'canEdit' => false,
    'editMethod' => '',
    'editLabel' => 'Edit product',
    'canDelete' => false,
    'removeMethod' => '',
    'removeLabel' => 'Remove product',
    'confirmText' => 'Remove this product?',
    'canRestore' => false,
    'restoreMethod' => '',
    'restoreLabel' => 'Restore product',
    'disabled' => false,
    'disabledTitle' => 'This product cannot be changed.',
])

@php
    $hasEdit = $itemId && $canEdit && $editMethod !== '';
    $hasDelete = $itemId && $canDelete && $removeMethod !== '';
    $hasRestore = $itemId && $canRestore && $restoreMethod !== '';

    $editTarget = $hasEdit ? $editMethod.'('.(int) $itemId.')' : null;
    $removeTarget = $hasDelete ? $removeMethod.'('.(int) $itemId.')' : null;
    $restoreTarget = $hasRestore ? $restoreMethod.'('.(int) $itemId.')' : null;

    $actionCount = (int) $hasEdit + (int) $hasDelete + (int) $hasRestore;
    // Matches the compact menu button sizing: 36px per action + 12px popover padding.
    $estimatedMenuHeight = max(48, ($actionCount * 36) + 12);
@endphp

@if($hasEdit || $hasDelete || $hasRestore)
    <div
        class="ft-order-product-row-menu"
        x-data="{
            open: false,
            top: 0,
            left: 0,
            menuWidth: 156,
            menuHeight: {{ $estimatedMenuHeight }},
            place() {
                const trigger = this.$refs.trigger;
                if (!trigger) return;

                const rect = trigger.getBoundingClientRect();
                const gap = 6;
                const edge = 10;
                const maxLeft = Math.max(edge, window.innerWidth - this.menuWidth - edge);

                this.left = Math.max(edge, Math.min(maxLeft, rect.right - this.menuWidth));

                const roomBelow = window.innerHeight - rect.bottom;
                const roomAbove = rect.top;
                const needed = this.menuHeight + gap + edge;

                if (roomBelow >= needed) {
                    this.top = rect.bottom + gap;
                    return;
                }

                if (roomAbove >= needed) {
                    this.top = rect.top - this.menuHeight - gap;
                    return;
                }

                const preferred = roomBelow >= roomAbove
                    ? rect.bottom + gap
                    : rect.top - this.menuHeight - gap;

                this.top = Math.max(
                    edge,
                    Math.min(window.innerHeight - this.menuHeight - edge, preferred)
                );
            },
            toggle() {
                if (this.open) {
                    this.open = false;
                    return;
                }

                this.place();
                this.open = true;
            }
        }"
        x-on:keydown.escape.window="open = false"
        x-on:resize.window="open = false"
        x-on:scroll.window="open = false"
    >
        <button
            x-ref="trigger"
            type="button"
            class="ft-order-product-kebab"
            x-on:click.stop="toggle()"
            x-bind:aria-expanded="open ? 'true' : 'false'"
            aria-haspopup="menu"
            aria-label="Product actions"
        >⋮</button>

        <template x-teleport="body">
            <div
                class="ft-detail-product-menu-portal"
                x-cloak
                x-show="open"
                x-transition.opacity.duration.120ms
                x-bind:style="`top: ${top}px; left: ${left}px;`"
                role="menu"
                x-on:click.outside="open = false"
            >
                @if($hasRestore)
                    <button
                        type="button"
                        class="is-neutral"
                        role="menuitem"
                        wire:click.stop="{{ $restoreTarget }}"
                        wire:loading.attr="disabled"
                        wire:target="{{ $restoreTarget }}"
                        x-on:click="open = false"
                        @if($disabled) disabled title="{{ $disabledTitle }}" @endif
                    >{{ $restoreLabel }}</button>
                @endif

                @if($hasEdit)
                    <button
                        type="button"
                        class="is-neutral"
                        role="menuitem"
                        wire:click.stop="{{ $editTarget }}"
                        wire:loading.attr="disabled"
                        wire:target="{{ $editTarget }}"
                        x-on:click="open = false"
                        @if($disabled) disabled title="{{ $disabledTitle }}" @endif
                    >{{ $editLabel }}</button>
                @endif

                @if($hasDelete)
                    <button
                        type="button"
                        role="menuitem"
                        wire:click.stop="{{ $removeTarget }}"
                        wire:confirm="{{ $confirmText }}"
                        wire:loading.attr="disabled"
                        wire:target="{{ $removeTarget }}"
                        x-on:click="open = false"
                        @if($disabled) disabled title="{{ $disabledTitle }}" @endif
                    >{{ $removeLabel }}</button>
                @endif
            </div>
        </template>
    </div>
@else
    <span class="ft-order-product-action-placeholder">—</span>
@endif
