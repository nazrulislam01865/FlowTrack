@props([
    'selected' => null,
    'methods' => collect(),
    'urgencies' => collect(),
    'taskId' => null,
    'shipmentId' => null,
    'mode' => 'row',
    'disabled' => false,
    'appearance' => 'field',
])

@php
    $presenter = \App\Support\CreateOrderShippingMethodPresenter::class;
    $methods = collect($methods)->values();
    $urgencies = collect($urgencies)->values();
    $directMethods = $presenter::directMethods($methods);
    $expressMethod = $presenter::expressMethod($methods);
    $expressUrgencies = $presenter::expressUrgencies($urgencies);
    $hardDisabled = (bool) $disabled;
    $clientDisabledExpression = $hardDisabled ? 'true' : 'false';
    $inlineAppearance = $appearance === 'inline';
    $action = match ($mode) {
        'modal' => 'selectShipmentModalMethod',
        'inline' => 'selectInlineShipmentMethod',
        default => 'selectOrderShipmentMethod',
    };
@endphp

<div
    class="ft-ms-method {{ $inlineAppearance ? 'ft-ms-method--inline-display' : '' }}"
    x-data="{
        ...window.FlowTrack.ui.floatingActionMenu(),
        menuZIndex: 2450,
        open: false,
        toggleMenu() {
            if ({!! $clientDisabledExpression !!}) return;
            if (this.open) { this.closeMenu(); return; }
            this.menuStyle = 'visibility:hidden!important;pointer-events:none!important';
            this.open = true;
            this.$nextTick(() => this.positionMenu());
        },
        closeMenu() {
            this.open = false;
            this.menuStyle = '';
        }
    }"
    x-effect="if ({!! $clientDisabledExpression !!} && open) closeMenu()"
    x-on:keydown.escape.window="closeMenu()"
    x-on:resize.window="open && positionMenu()"
    x-on:scroll.window="open && positionMenu()"
>
    <button
        x-ref="trigger"
        type="button"
        class="ft-ms-method__trigger"
        :class="open ? 'is-open' : ''"
        x-on:click.stop="toggleMenu()"
        x-bind:disabled="{!! $clientDisabledExpression !!}"
        :aria-expanded="open.toString()"
        aria-haspopup="listbox"
    >
        @if($selected)
            <span class="ft-ms-method__icon"><x-jobs.create.shipping-method-icon :type="$selected['kind']" /></span>
            <span class="ft-ms-method__copy">
                <strong>{{ $selected['title'] }}</strong>
            </span>
        @else
            <span class="ft-ms-method__copy"><strong>Select shipping method</strong></span>
        @endif

        @if($inlineAppearance)
            <svg
                class="ft-ms-method__edit-icon"
                x-cloak
                x-show="!({!! $clientDisabledExpression !!})"
                viewBox="0 0 20 20"
                fill="none"
                stroke="currentColor"
                stroke-width="1.7"
                aria-hidden="true"
            ><path d="m4 14.5-.5 2 2-.5L14 7.5 12.5 6 4 14.5Z"/><path d="m11.5 7 1.5-1.5a1.1 1.1 0 0 1 1.6 0l.4.4a1.1 1.1 0 0 1 0 1.6L13.5 9"/></svg>
        @else
            <svg
                class="ft-ms-method__chevron"
                x-cloak
                x-show="!({!! $clientDisabledExpression !!})"
                :class="open ? 'is-open' : ''"
                viewBox="0 0 20 20"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
                aria-hidden="true"
            ><path d="m6 8 4 4 4-4"/></svg>
        @endif
    </button>

    @unless($hardDisabled)
        <template x-teleport="body">
            <div
                x-ref="menu"
                class="ft-ms-method__menu ft-ms-method-portal"
                x-cloak
                x-show="open"
                x-bind:style="menuStyle + (open ? ';display:block!important;' : ';display:none!important;')"
                x-on:click.outside="closeMenu()"
                role="listbox"
            >
                @foreach($directMethods as $method)
                    @php
                        $kind = $presenter::methodKind($method);
                        $label = $presenter::methodLabel($method);
                        $actionArgs = in_array($mode, ['modal', 'inline'], true)
                            ? ((int) $method->id).', null'
                            : ((int) $taskId).', '.((int) $shipmentId).', '.((int) $method->id).', null';
                    @endphp
                    <button
                        type="button"
                        class="ft-ms-method__option"
                        x-on:click.stop="closeMenu(); $wire.call(@js($action), {!! $actionArgs !!})"
                    >
                        <span class="ft-ms-method__icon"><x-jobs.create.shipping-method-icon :type="$kind" /></span>
                        <span class="ft-ms-method__copy">
                            <strong>{{ $label }}</strong>
                        </span>
                    </button>
                @endforeach

                @if($expressMethod)
                    <div class="ft-ms-method__group-title">
                        <span>STANDARD EXPRESS SHIPPING</span>
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="10" cy="10" r="7.2"/><path d="M10 8.7v4.4M10 6.2h.01"/></svg>
                    </div>
                    @foreach($expressUrgencies as $urgency)
                        @php
                            $urgencyId = $urgency['id'];
                            $urgencyArg = $urgencyId === null ? 'null' : (string) ((int) $urgencyId);
                            $actionArgs = in_array($mode, ['modal', 'inline'], true)
                                ? ((int) $expressMethod->id).', '.$urgencyArg
                                : ((int) $taskId).', '.((int) $shipmentId).', '.((int) $expressMethod->id).', '.$urgencyArg;
                        @endphp
                        <button
                            type="button"
                            class="ft-ms-method__option ft-ms-method__option--express"
                            x-on:click.stop="closeMenu(); $wire.call(@js($action), {!! $actionArgs !!})"
                        >
                            <span class="ft-ms-method__icon"><x-jobs.create.shipping-method-icon type="express" /></span>
                            <span class="ft-ms-method__copy ft-ms-method__copy--inline">
                                <strong>{{ $urgency['name'] }}</strong>
                            </span>
                        </button>
                    @endforeach
                @endif
            </div>
        </template>
    @endunless
</div>
