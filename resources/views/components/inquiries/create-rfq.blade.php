@props([
    'suppliers' => collect(),
    'selectedSuppliers' => collect(),
    'selectedSupplierIds' => [],
    'supplierSearch' => '',
    'productCount' => 0,
])

@php
    $selectedIds = collect($selectedSupplierIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
    $selectedCount = $selectedIds->count();
    $selectedSupplierRows = collect($selectedSuppliers)
        ->filter(fn ($supplier) => $selectedIds->contains((int) data_get($supplier, 'id')))
        ->values();
@endphp

<section {{ $attributes->class(['ft-create-rfq-layout']) }} aria-labelledby="create-rfq-title">
    <div class="ft-create-rfq-card ft-create-rfq-card--suppliers">
        <header class="ft-create-rfq-head">
            <div class="ft-create-rfq-title-wrap">
                <div class="ft-create-rfq-title-line">
                    <h2 id="create-rfq-title">Invite suppliers to the RFQ</h2>
                    <span class="ft-create-rfq-optional">Optional</span>
                </div>
                <p>You may invite one or two now, or do this later from the RFQ page.</p>
            </div>
            <span class="ft-create-rfq-selected-pill">{{ $selectedCount }} selected</span>
        </header>

        <div class="ft-create-rfq-body">
            <label class="ft-create-rfq-search-label" for="create-rfq-supplier-search">Search suppliers</label>
            <div class="ft-create-rfq-search">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle cx="11" cy="11" r="5.5"></circle>
                    <path d="m15.5 15.5 4 4"></path>
                </svg>
                <input
                    id="create-rfq-supplier-search"
                    type="search"
                    wire:model.live.debounce.300ms="createRfqSupplierSearch"
                    placeholder="Search supplier name, category or email"
                    autocomplete="off"
                >
            </div>
            @error('createRfqSupplierIds')<p class="ft-create-rfq-error">{{ $message }}</p>@enderror

            <div class="ft-create-rfq-supplier-list">
                @forelse($suppliers as $supplier)
                    @php $supplierId = (int) ($supplier['id'] ?? 0); @endphp
                    <x-inquiries.rfq-supplier-choice
                        :supplier="$supplier"
                        :selected="$selectedIds->contains($supplierId)"
                        wire:key="create-rfq-supplier-{{ $supplierId }}"
                    />
                @empty
                    <div class="ft-create-rfq-empty">
                        {{ trim((string) $supplierSearch) !== '' ? 'No suppliers match your search.' : 'No suppliers are available in the Supplier list.' }}
                    </div>
                @endforelse
            </div>

        </div>
    </div>

    <aside class="ft-create-rfq-card ft-create-rfq-card--settings" aria-labelledby="create-rfq-settings-title">
        <header class="ft-create-rfq-settings-head">
            <h2 id="create-rfq-settings-title">RFQ settings</h2>
        </header>
        <div class="ft-create-rfq-settings-body">
            <label class="ft-create-rfq-field">
                <span>Quotation due</span>
                <input type="date" wire:model="createRfqDueDate">
                @error('createRfqDueDate')<small class="ft-create-rfq-error">{{ $message }}</small>@enderror
            </label>

            <label class="ft-create-rfq-field">
                <span>Message</span>
                <textarea wire:model="createRfqMessage"></textarea>
                @error('createRfqMessage')<small class="ft-create-rfq-error">{{ $message }}</small>@enderror
            </label>

            <div class="ft-create-rfq-summary">
                <div><span>Products</span><strong>{{ number_format((int) $productCount) }}</strong></div>
                <div><span>Invite now</span><strong>{{ number_format($selectedCount) }} {{ \Illuminate\Support\Str::plural('supplier', $selectedCount) }}</strong></div>
                <div><span>If none<br>selected</span><strong>RFQ remains ready to invite</strong></div>
            </div>
        </div>
    </aside>
</section>

@if($selectedSupplierRows->isNotEmpty())
    <section class="ft-create-rfq-selected-section" aria-labelledby="create-rfq-selected-title">
        <div class="ft-create-rfq-selected-head">
            <div>
                <h3 id="create-rfq-selected-title">Selected suppliers</h3>
                <p>These suppliers will be added to this Inquiry RFQ when you create the Inquiry.</p>
            </div>
            <span>{{ number_format($selectedSupplierRows->count()) }} {{ \Illuminate\Support\Str::plural('supplier', $selectedSupplierRows->count()) }}</span>
        </div>

        <div class="ft-create-rfq-selected-list">
            @foreach($selectedSupplierRows as $supplier)
                <x-inquiries.rfq-selected-supplier-card
                    :supplier="$supplier"
                    wire:key="create-rfq-selected-supplier-{{ (int) data_get($supplier, 'id') }}"
                />
            @endforeach
        </div>
    </section>
@endif

