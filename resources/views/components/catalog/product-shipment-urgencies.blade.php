@props([
    'number' => null,
    'shipmentUrgencies' => collect(),
    'selectedUrgencies' => [],
    'pickerOpen' => false,
    'pickerSelection' => [],
])
@php
    $urgencies = collect($shipmentUrgencies)->values();
    $urgenciesById = $urgencies->keyBy(fn ($urgency) => (int) $urgency->id);
    $selectedIds = collect($selectedUrgencies)
        ->pluck('shipment_urgency_id')
        ->map(fn ($value) => (int) $value)
        ->filter()
        ->values();
    $pendingIds = collect($pickerSelection)->map(fn ($value) => (int) $value)->filter()->unique()->values();
@endphp
<x-catalog.product-section
    :number="$number"
    title="Shipping urgencies"
    subtitle="Choose only the urgency levels this product supports. Add a product-specific extra charge when needed."
    class="ft-product-shipping-section"
>
    @if($selectedIds->isNotEmpty())
        <div class="ft-product-shipping-selected-grid">
            @foreach($selectedUrgencies as $urgencyIndex => $selectedUrgency)
                @php
                    $urgencyId = (int) data_get($selectedUrgency, 'shipment_urgency_id', 0);
                    $urgency = $urgenciesById->get($urgencyId);
                    $urgencyName = $urgency?->name ?: 'Shipping urgency';
                    $urgencyCode = $urgency?->code ?: '';
                    $urgencyColor = \App\Support\MasterColor::normalize($urgency?->color) ?: '#00897B';
                @endphp
                <article class="ft-product-shipping-selected-card" wire:key="product-shipment-urgency-{{ data_get($selectedUrgency, 'key', $urgencyIndex) }}">
                    <div class="ft-product-shipping-selected-head">
                        <span class="ft-product-shipping-selected-icon" style="--urgency-color: {{ $urgencyColor }}">↗</span>
                        <div>
                            <strong>{{ $urgencyName }}</strong>
                            @if($urgencyCode)<small>{{ $urgencyCode }}</small>@endif
                        </div>
                        <button type="button" class="ft-product-shipping-card-remove" wire:click="removeProductShipmentUrgency({{ $urgencyIndex }})" aria-label="Remove {{ $urgencyName }}">×</button>
                    </div>
                    <label class="ft-product-field ft-product-shipping-card-charge">
                        <span>Extra charge <em>Optional</em></span>
                        <input type="number" min="0" step="0.01" inputmode="decimal" wire:model.blur="productShipmentUrgencies.{{ $urgencyIndex }}.extra_charge" placeholder="0.00">
                        @error('productShipmentUrgencies.'.$urgencyIndex.'.extra_charge')<b class="validation-error">{{ $message }}</b>@enderror
                    </label>
                    @error('productShipmentUrgencies.'.$urgencyIndex.'.shipment_urgency_id')<b class="validation-error">{{ $message }}</b>@enderror
                </article>
            @endforeach
        </div>
    @else
        <div class="ft-product-shipping-empty-state">
            <span class="ft-product-shipping-empty-icon">↗</span>
            <div>
                <strong>No shipping urgencies added</strong>
                <small>Add only the urgency levels available for this product.</small>
            </div>
        </div>
    @endif

    <div class="ft-product-shipping-actions">
        <button
            type="button"
            class="ft-product-shipping-add"
            wire:click="addProductShipmentUrgency"
            @disabled($urgencies->isEmpty() || $selectedIds->count() >= 20)
        >
            <span>+</span> Add shipping urgency
        </button>
        @if($selectedIds->isNotEmpty())
            <small>{{ $selectedIds->count() }} selected</small>
        @endif
    </div>

    @if($urgencies->isEmpty())
        <div class="ft-product-shipping-master-empty">Add active Shipment Urgencies in Master Data before assigning them to a product.</div>
    @endif
    @error('productShipmentUrgencies')<b class="validation-error ft-product-shipping-error">{{ $message }}</b>@enderror

    @if($pickerOpen)
        <div class="ft-product-shipping-picker-backdrop" wire:key="product-shipping-urgency-picker" wire:click.self="closeProductShipmentUrgencyPicker">
            <section class="ft-product-shipping-picker" role="dialog" aria-modal="true" aria-labelledby="product-shipping-urgency-picker-title" x-data="{ urgencySearch: '' }" x-on:keydown.escape.window="$wire.closeProductShipmentUrgencyPicker()">
                <header class="ft-product-shipping-picker-head">
                    <div>
                        <span class="ft-product-shipping-picker-kicker">Shipping setup</span>
                        <h3 id="product-shipping-urgency-picker-title">Add shipping urgencies</h3>
                        <p>Select one or more urgency levels for this product.</p>
                    </div>
                    <button type="button" wire:click="closeProductShipmentUrgencyPicker" aria-label="Close shipping urgency picker">×</button>
                </header>

                <div class="ft-product-shipping-picker-tools">
                    <label class="ft-product-shipping-picker-search">
                        <span>⌕</span>
                        <input type="search" x-model="urgencySearch" placeholder="Search shipping urgencies…" autocomplete="off">
                    </label>
                    <small>{{ $selectedIds->count() }} already added</small>
                </div>

                <div class="ft-product-shipping-picker-body">
                    <div class="ft-product-shipping-picker-grid">
                        @foreach($urgencies as $urgency)
                            @php
                                $urgencyId = (int) $urgency->id;
                                $alreadyAdded = $selectedIds->contains($urgencyId);
                                $pending = $pendingIds->contains($urgencyId);
                                $urgencyColor = \App\Support\MasterColor::normalize($urgency->color) ?: '#00897B';
                                $searchText = mb_strtolower(trim($urgency->name.' '.$urgency->code.' '.$urgency->description));
                            @endphp
                            <button
                                type="button"
                                class="ft-product-shipping-picker-card {{ $alreadyAdded ? 'is-added' : '' }} {{ $pending ? 'is-selected' : '' }}"
                                style="--urgency-color: {{ $urgencyColor }}"
                                x-show="!urgencySearch || @js($searchText).includes(urgencySearch.toLowerCase())"
                                @if(!$alreadyAdded) wire:click="toggleProductShipmentUrgencyPickerSelection({{ $urgencyId }})" @endif
                                @disabled($alreadyAdded)
                                aria-pressed="{{ $pending ? 'true' : 'false' }}"
                            >
                                <span class="ft-product-shipping-picker-card-mark">{{ $alreadyAdded || $pending ? '✓' : '' }}</span>
                                <span class="ft-product-shipping-picker-card-icon">↗</span>
                                <span class="ft-product-shipping-picker-card-copy">
                                    <strong>{{ $urgency->name }}</strong>
                                    @if($urgency->code)<small>{{ $urgency->code }}</small>@endif
                                    @if($urgency->description)<em>{{ \Illuminate\Support\Str::limit($urgency->description, 78) }}</em>@endif
                                </span>
                                <span class="ft-product-shipping-picker-card-status">{{ $alreadyAdded ? 'Added' : ($pending ? 'Selected' : 'Select') }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>

                <footer class="ft-product-shipping-picker-footer">
                    <div>
                        <strong>{{ $pendingIds->count() }}</strong>
                        <span>{{ \Illuminate\Support\Str::plural('urgency', $pendingIds->count()) }} selected to add</span>
                    </div>
                    <div>
                        <button type="button" class="is-secondary" wire:click="closeProductShipmentUrgencyPicker">Cancel</button>
                        <button type="button" class="is-primary" wire:click="confirmProductShipmentUrgencies" @disabled($pendingIds->isEmpty())>Add selected</button>
                    </div>
                </footer>
            </section>
        </div>
    @endif
</x-catalog.product-section>
