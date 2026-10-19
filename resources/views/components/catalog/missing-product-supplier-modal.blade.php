@props([
    'show' => false,
    'productName' => '',
    'choice' => 'create',
    'existingSupplierId' => null,
    'existingSupplierLabel' => '',
    'newSupplierName' => '',
    'newSupplierEmail' => '',
    'allowSkip' => true,
    'recordLabel' => 'Order',
    'submitMode' => 'add',
    'selectorContext' => 'create-job',
])

@if($show)
    <x-ui.modal
        id="missing-product-supplier"
        title="Supplier not linked"
        size="md"
        :open="true"
        class="ft-order-supplier-resolution-modal"
        x-data
        x-on:keydown.escape.window="$wire.closeMissingProductSupplierModal()"
    >
        <x-slot:close>
            <button
                type="button"
                class="ft-order-supplier-resolution__close"
                wire:click="closeMissingProductSupplierModal"
                aria-label="Close supplier resolution"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M6 6l12 12M18 6 6 18" />
                </svg>
            </button>
        </x-slot:close>

        <div class="ft-order-supplier-resolution">
            <div class="ft-order-supplier-resolution__notice" role="status">
                <span class="ft-order-supplier-resolution__notice-icon" aria-hidden="true">!</span>
                <div class="ft-order-supplier-resolution__notice-copy">
                    <strong>{{ $productName }}</strong>
                    <p>This product has no supplier linked. Choose one option below.</p>
                </div>
            </div>

            <div class="ft-order-supplier-resolution__options" role="radiogroup" aria-label="Supplier resolution">
                <section class="ft-order-supplier-resolution__option {{ $choice === 'existing' ? 'is-selected' : '' }}">
                    <label class="ft-order-supplier-resolution__option-heading">
                        <input type="radio" name="missing-product-supplier-choice" value="existing" wire:model.live="missingProductSupplierChoice">
                        <span class="ft-order-supplier-resolution__radio" aria-hidden="true"></span>
                        <strong>Link existing supplier</strong>
                    </label>

                    <div class="ft-order-supplier-resolution__option-content">
                        <x-ui.search-select
                            class="ft-order-supplier-resolution__supplier-select"
                            label="Supplier"
                            property="missingProductExistingSupplierId"
                            type="suppliers"
                            :context="$selectorContext"
                            action="selectMissingProductSupplier"
                            :value="$existingSupplierId"
                            :selected-label="$existingSupplierLabel ?: null"
                            placeholder="Search supplier"
                            search-placeholder="Search supplier"
                            :clearable="false"
                            :hide-label="true"
                            :fixed-menu="true"
                            :menu-width="420"
                        />
                        @error('missingProductExistingSupplierId')
                            <x-ui.validation-message :message="$message" />
                        @enderror
                    </div>
                </section>

                <section class="ft-order-supplier-resolution__option {{ $choice === 'create' ? 'is-selected' : '' }}">
                    <label class="ft-order-supplier-resolution__option-heading">
                        <input type="radio" name="missing-product-supplier-choice" value="create" wire:model.live="missingProductSupplierChoice">
                        <span class="ft-order-supplier-resolution__radio" aria-hidden="true"></span>
                        <strong>Create new supplier</strong>
                    </label>

                    <div class="ft-order-supplier-resolution__option-content ft-order-supplier-resolution__new-supplier-fields">
                        <x-ui.input
                            label="Supplier name"
                            name="missingProductNewSupplierName"
                            wire:model.blur="missingProductNewSupplierName"
                            placeholder="Enter supplier name"
                            :required="true"
                        />
                        <x-ui.input
                            label="Email"
                            name="missingProductNewSupplierEmail"
                            type="email"
                            wire:model.blur="missingProductNewSupplierEmail"
                            placeholder="Enter email address"
                            :optional="true"
                        />
                    </div>
                </section>

                @if($allowSkip)
                    <section class="ft-order-supplier-resolution__option {{ $choice === 'skip' ? 'is-selected' : '' }}">
                        <label class="ft-order-supplier-resolution__option-heading">
                            <input type="radio" name="missing-product-supplier-choice" value="skip" wire:model.live="missingProductSupplierChoice">
                            <span class="ft-order-supplier-resolution__radio" aria-hidden="true"></span>
                            <strong>Continue without supplier</strong>
                        </label>
                        <p class="ft-order-supplier-resolution__helper">You can assign one later from {{ $recordLabel }} Details.</p>
                    </section>
                @endif
            </div>
        </div>

        <x-slot:footer>
            <div class="ft-order-supplier-resolution__actions">
                <x-ui.button type="button" variant="secondary" wire:click="closeMissingProductSupplierModal">Cancel</x-ui.button>

                <x-ui.button
                    type="button"
                    class="ft-order-supplier-resolution__primary-action"
                    wire:click="resolveMissingProductSupplier"
                    wire:loading.attr="disabled"
                    wire:target="resolveMissingProductSupplier"
                >
                    <span wire:loading.remove wire:target="resolveMissingProductSupplier">
                        @if($choice === 'existing')
                            {{ $submitMode === 'continue' ? 'Link supplier & continue' : 'Link supplier & add product' }}
                        @elseif($choice === 'skip')
                            {{ $submitMode === 'continue' ? 'Continue without supplier' : 'Add product without supplier' }}
                        @else
                            {{ $submitMode === 'continue' ? 'Create supplier & continue' : 'Create supplier & add product' }}
                        @endif
                    </span>
                    <span wire:loading wire:target="resolveMissingProductSupplier">Saving...</span>
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>
@endif
