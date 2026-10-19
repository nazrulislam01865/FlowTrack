@props([
    'candidates',
    'products' => [],
    'selectedProductId' => null,
])

@php
    $products = collect($products);
    $selectedProduct = $selectedProductId ? $products->firstWhere('id', (int) $selectedProductId) : null;
@endphp

<div class="ft-rfq-add-modal-backdrop" wire:key="rfq-add-supplier-modal" wire:click.self="closeRfqSupplierPicker" x-data x-on:keydown.escape.window="$wire.closeRfqSupplierPicker()">
    <section class="ft-rfq-add-modal" role="dialog" aria-modal="true" aria-labelledby="rfq-add-supplier-title">
        <header class="ft-rfq-add-modal-head">
            <div>
                <h2 id="rfq-add-supplier-title">Add supplier</h2>
                <p>
                    @if($selectedProduct)
                        Add an active supplier to {{ $selectedProduct['name'] }}. Sending the invitation remains a separate action.
                    @else
                        Choose a product, then add an active supplier to its RFQ.
                    @endif
                </p>
            </div>
            <button type="button" wire:click="closeRfqSupplierPicker" aria-label="Close add supplier">×</button>
        </header>

        <div class="ft-rfq-add-modal-body">
            @if(! $selectedProduct)
                <label class="ft-rfq-add-modal-product">
                    <span>Product</span>
                    <select wire:model.live.number="rfqSupplierProductId">
                        <option value="">Select product</option>
                        @foreach($products as $product)
                            <option value="{{ (int) $product['id'] }}">
                                {{ $product['name'] }}@if(filled($product['code'] ?? null)) · {{ $product['code'] }}@endif
                            </option>
                        @endforeach
                    </select>
                </label>
            @else
                <div class="ft-rfq-add-modal-product-summary">
                    <span>Product</span>
                    <strong>{{ $selectedProduct['name'] }}</strong>
                    @if(filled($selectedProduct['code'] ?? null))<small>{{ $selectedProduct['code'] }}</small>@endif
                </div>
            @endif

            <label class="ft-rfq-add-modal-search {{ ! $selectedProductId ? 'is-disabled' : '' }}">
                <span class="sr-only">Search supplier directory</span>
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="11" cy="11" r="6.5"></circle><path d="m16 16 4 4"></path></svg>
                <input
                    type="search"
                    wire:model.live.debounce.300ms="rfqSupplierSearch"
                    placeholder="Search supplier name, category or email"
                    autocomplete="off"
                    @disabled(! $selectedProductId)
                    @if($selectedProductId) autofocus @endif
                >
            </label>
            @error('rfqSupplierSearch')<p class="ft-rfq-add-modal-error">{{ $message }}</p>@enderror

            <div class="ft-rfq-add-modal-list">
                @if(! $selectedProductId)
                    <div class="ft-rfq-add-modal-empty">Select a product to view available suppliers.</div>
                @else
                    @forelse($candidates as $candidate)
                        @php
                            $name = (string) ($candidate['name'] ?? 'Supplier');
                            $parts = preg_split('/\s+/u', trim($name)) ?: [];
                            $initials = collect($parts)->filter()->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->take(2)->implode('') ?: '—';
                            $email = trim((string) ($candidate['email'] ?? ''));
                            $isActive = (bool) ($candidate['invitable'] ?? false);
                        @endphp
                        <div class="ft-rfq-add-modal-row" wire:key="rfq-add-candidate-{{ $selectedProductId }}-{{ $candidate['id'] }}">
                            <span class="ft-rfq-management-avatar">{{ $initials }}</span>
                            <div class="ft-rfq-add-modal-copy">
                                <strong>{{ $name }}</strong>
                                <span>{{ $candidate['category'] ?? 'General supplier' }} · {{ $email !== '' ? $email : 'No email configured' }}</span>
                            </div>
                            @if($isActive)
                                <button type="button" wire:click="addRfqSupplier({{ (int) $candidate['id'] }})" wire:loading.attr="disabled" wire:target="addRfqSupplier({{ (int) $candidate['id'] }})">
                                    <span wire:loading.remove wire:target="addRfqSupplier({{ (int) $candidate['id'] }})">Add</span>
                                    <span wire:loading wire:target="addRfqSupplier({{ (int) $candidate['id'] }})">Adding…</span>
                                </button>
                            @else
                                <span class="ft-rfq-add-modal-unavailable">{{ $candidate['unavailable_reason'] ?? 'Inactive' }}</span>
                            @endif
                        </div>
                    @empty
                        <div class="ft-rfq-add-modal-empty">No available suppliers match your search.</div>
                    @endforelse
                @endif
            </div>
        </div>
    </section>
</div>
