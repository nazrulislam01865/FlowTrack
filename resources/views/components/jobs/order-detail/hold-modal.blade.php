@props([
    'job',
    'holdFrom' => 'client',
    'reason' => '',
    'mentionUsers' => collect(),
])
@php
    $holdOptions = \App\Models\OrderHold::HOLD_FROM_OPTIONS;
    $mentionOptions = collect($mentionUsers)->values()->all();
@endphp
<div
    class="ft-order-modal-backdrop ft-order-hold-modal-backdrop"
    wire:key="order-hold-modal-{{ $job->id }}"
    wire:click.self="closeOrderHoldModal"
>
    <section
        class="ft-order-modal ft-order-hold-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="order-hold-modal-title"
        x-data="{ selected: @js($holdFrom), reason: @js($reason) }"
        x-on:keydown.escape.window="$wire.closeOrderHoldModal()"
    >
        <header class="ft-order-hold-modal__header">
            <div>
                <h2 id="order-hold-modal-title">Hold order</h2>
                <p>Pause this order and lock all order and task activities until the order is unheld.</p>
            </div>
            <button type="button" wire:click="closeOrderHoldModal" aria-label="Close hold order dialog">×</button>
        </header>

        <div class="ft-order-modal-body ft-order-hold-modal__body">
            <fieldset class="ft-order-hold-fieldset">
                <legend>Hold from <span aria-hidden="true">*</span></legend>
                <div class="ft-order-hold-options">
                    @foreach($holdOptions as $value => $label)
                        <label class="ft-order-hold-option" :class="selected === @js($value) ? 'is-selected' : ''">
                            <input
                                type="radio"
                                name="order_hold_from"
                                value="{{ $value }}"
                                x-model="selected"
                                wire:model="orderHoldFrom"
                            >
                            <span class="ft-order-hold-option__radio" aria-hidden="true"></span>
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                @error('orderHoldFrom')
                    <p class="validation-error">{{ $message }}</p>
                @enderror
            </fieldset>

            <div class="ft-order-hold-reason-field ft-mention-host">
                <div class="ft-order-hold-reason-label-row">
                    <label for="order-hold-reason">Reason <span class="ft-order-hold-optional">Optional</span></label>
                    <span>Type <b>@</b> to mention a user</span>
                </div>
                <textarea
                    id="order-hold-reason"
                    class="ft-mention-input"
                    rows="5"
                    maxlength="500"
                    autocomplete="off"
                    x-model="reason"
                    wire:model="orderHoldReason"
                    data-mention-users="{{ json_encode($mentionOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
                    placeholder="Add an optional reason or mention someone with @..."
                ></textarea>
                <div class="ft-order-hold-reason-count"><span x-text="reason.length">{{ mb_strlen($reason) }}</span>/500</div>
                @error('orderHoldReason')
                    <p class="validation-error">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <footer class="ft-order-hold-modal__footer">
            <button type="button" class="secondary" wire:click="closeOrderHoldModal" wire:loading.attr="disabled" wire:target="placeOrderOnHold" data-ft-feedback="off">
                Cancel
            </button>
            <button type="button" class="ft-order-hold-submit" wire:click="placeOrderOnHold" wire:loading.attr="disabled" wire:target="placeOrderOnHold" data-ft-feedback="off">
                <span wire:loading.remove wire:target="placeOrderOnHold" class="ft-order-hold-button-content">
                    <span class="ft-order-hold-pause-icon" aria-hidden="true"><i></i><i></i></span>
                    <span>Hold order</span>
                </span>
                <span wire:loading wire:target="placeOrderOnHold" class="ft-order-hold-button-content">
                    <span class="ft-order-hold-mini-spinner" aria-hidden="true"></span>
                    <span>Holding...</span>
                </span>
            </button>
        </footer>
    </section>
</div>
