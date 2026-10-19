@php
    $supplierProducts = collect($supplierListSummary['products_by_supplier'] ?? []);
    $assignedProducts = (int) ($supplierListSummary['assigned_products'] ?? 0);
    $totalProducts = (int) ($supplierListSummary['total_products'] ?? 0);
@endphp

<div class="ft-supplier-list-page">
    <div class="ft-supplier-list-breadcrumb" aria-label="Breadcrumb">
        <span>Master data</span><i>/</i><strong>Suppliers</strong>
    </div>

    <header class="ft-supplier-list-head">
        <div>
            <h1>Suppliers</h1>
            <p>Manage supplier information and see which products each supplier supports.</p>
        </div>
        <div class="ft-supplier-list-head-actions">
            @if(auth()->user()->canModule('catalog_products', 'view'))
                <a href="{{ route('master-data', ['group' => 'product', 'supplier_assign' => 1]) }}" wire:navigate class="ft-supplier-list-button is-secondary">Assign from products</a>
            @endif
            @if($canCreateMaster)
                <a href="{{ route('master-data', ['group' => 'supplier', 'create' => 1]) }}" wire:navigate class="ft-supplier-list-button is-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                    <span>Create supplier</span>
                </a>
            @endif
        </div>
    </header>

    @if(session('success'))<div class="flash success ft-master-flash">{{ session('success') }}</div>@endif
    @if(session('warning'))<div class="flash error ft-master-flash" role="alert">{{ session('warning') }}</div>@endif
    @error('record')<div class="flash error ft-master-flash">{{ $message }}</div>@enderror

    <div class="ft-supplier-list-summary">
        <x-suppliers.stat-card label="Active suppliers" :value="number_format((int) ($supplierListSummary['active_suppliers'] ?? 0))" icon="supplier" />
        <x-suppliers.stat-card label="Products assigned" :value="number_format($assignedProducts).' of '.number_format($totalProducts)" icon="product" />
        <x-suppliers.stat-card label="Products without supplier" :value="number_format((int) ($supplierListSummary['unassigned_products'] ?? 0))" icon="attention" />
    </div>

    <section class="ft-supplier-list-card">
        <div class="ft-supplier-list-toolbar">
            <label class="ft-supplier-list-search">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input
                    wire:model.live.debounce.300ms="search"
                    type="search"
                    placeholder="Search supplier or product code"
                    aria-label="Search supplier or product code"
                >
            </label>

            <div class="ft-supplier-list-toolbar-actions">
                <select wire:model.live="supplierStatus" class="ft-supplier-list-filter" aria-label="Filter supplier status">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
                <button type="button" class="ft-supplier-list-button is-secondary" wire:click="exportSuppliers" wire:loading.attr="disabled" wire:target="exportSuppliers">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3v12M7 10l5 5 5-5M5 20h14"/></svg>
                    <span wire:loading.remove wire:target="exportSuppliers">Export</span>
                    <span wire:loading wire:target="exportSuppliers">Exporting…</span>
                </button>
            </div>
        </div>

        @if(!$recordsReady)
            @include('livewire.shared.table-rows-placeholder', ['columns' => 6, 'rows' => 8])
        @else
            <div class="ft-supplier-list-table-wrap" wire:key="supplier-records">
                <table class="ft-supplier-list-table">
                    <thead>
                        <tr>
                            <th>Supplier</th>
                            <th>Contact</th>
                            <th>Products</th>
                            <th>Status</th>
                            <th>Updated</th>
                            <th aria-label="Actions"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $supplier)
                            <x-suppliers.list-row
                                :supplier="$supplier"
                                :products="$supplierProducts->get((int) $supplier->id, collect())"
                                :display-timezone="$displayTimezone"
                                :can-edit="$canEditMaster"
                            />
                        @empty
                            <tr>
                                <td colspan="6">
                                    <div class="ft-supplier-list-empty">
                                        <strong>No suppliers found</strong>
                                        <span>Try a different search or filter.</span>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($rows->total() > 30)
                <div class="ft-supplier-list-pagination">
                    <span>Showing <b>{{ $rows->firstItem() ?? 0 }}–{{ $rows->lastItem() ?? 0 }}</b> of {{ number_format($rows->total()) }} suppliers</span>
                    <div>
                        <button type="button" wire:click="previousPage('masterPage')" @disabled($rows->onFirstPage())>Previous</button>
                        <span>Page {{ $rows->currentPage() }} of {{ $rows->lastPage() }}</span>
                        <button type="button" wire:click="nextPage('masterPage')" @disabled(!$rows->hasMorePages())>Next</button>
                    </div>
                </div>
            @endif
        @endif
    </section>
</div>
