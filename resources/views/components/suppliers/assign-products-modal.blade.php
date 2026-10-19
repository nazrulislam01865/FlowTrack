@props(['suppliers' => collect(), 'productCounts' => collect(), 'selectedSupplierIds' => [], 'selectionCount' => 0])
<x-catalog.bulk-modal
    title="Assign suppliers"
    :subtitle="'Choose suppliers for '.number_format($selectionCount).' selected '.\Illuminate\Support\Str::plural('product', $selectionCount).'. Existing supplier links will be kept.'"
    save-label="Assign suppliers"
    save-action="applyBulkProductSupplier"
>
    <div class="ft-supplier-assign-picker" x-data="{ q: '' }">
        <small>Select multiple suppliers; click again to deselect.</small>
        <label class="ft-supplier-assign-search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" x-model="q" placeholder="Search suppliers" autocomplete="off" aria-label="Search suppliers">
        </label>

        <div class="ft-supplier-assign-options">
            @forelse($suppliers as $supplier)
                @php
                    $selected = in_array((int) $supplier->id, array_map('intval', $selectedSupplierIds), true);
                    $name = trim((string) $supplier->name);
                    $initials = collect(preg_split('/\s+/', $name) ?: [])->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
                    $contact = trim((string) data_get($supplier->metadata, 'contact_person'));
                    $count = (int) ($productCounts[(int) $supplier->id] ?? 0);
                    $searchText = mb_strtolower($name.' '.$contact.' '.(string) $supplier->code);
                @endphp
                <button
                    type="button"
                    class="ft-supplier-assign-option {{ $selected ? 'is-selected' : '' }}"
                    x-show="!q || @js($searchText).includes(q.toLowerCase())"
                    wire:click="chooseBulkProductSupplier({{ $supplier->id }})"
                    aria-pressed="{{ $selected ? 'true' : 'false' }}"
                >
                    <span class="ft-supplier-assign-radio" aria-hidden="true"><i></i></span>
                    <span class="ft-supplier-assign-logo">{{ $initials ?: 'S' }}</span>
                    <span class="ft-supplier-assign-copy">
                        <b>{{ $supplier->name }}</b>
                        <small>{{ $contact !== '' ? $contact.' · ' : '' }}{{ number_format($count) }} {{ \Illuminate\Support\Str::plural('product', $count) }}</small>
                    </span>
                    <span class="ft-supplier-assign-status">Active</span>
                </button>
            @empty
                <div class="ft-supplier-assign-empty"><strong>No active suppliers found</strong><span>Create or activate a supplier first.</span></div>
            @endforelse
        </div>
    </div>
    <div class="ft-supplier-assign-note">This links all chosen suppliers to each selected product. Existing supplier links and each product's current default supplier are preserved.</div>
    @error('bulkProductSupplierIds')<b class="validation-error">{{ $message }}</b>@enderror
    @error('bulkProductSupplierIds.*')<b class="validation-error">{{ $message }}</b>@enderror
</x-catalog.bulk-modal>
