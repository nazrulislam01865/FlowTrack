@props([
    'workspace' => [],
    'canManage' => false,
    'canEditSuppliers' => false,
])

@php
    $stats = $workspace['stats'] ?? [];
    $groups = collect($workspace['groups'] ?? []);
    $selectedCount = (int) ($workspace['selected_count'] ?? 0);
    $selectedProductCount = (int) ($workspace['selected_product_count'] ?? 0);
    $filteredProductCount = (int) ($workspace['filtered_product_count'] ?? 0);
    $currentPage = (int) ($workspace['current_page'] ?? 1);
    $lastPage = (int) ($workspace['last_page'] ?? 1);
@endphp

<section class="ft-rfq-px-workspace" aria-labelledby="rfq-product-workspace-title">
    <header class="ft-rfq-px-head">
        <div>
            <h2 id="rfq-product-workspace-title">Product RFQ invitations</h2>
            <p>Invite suppliers and track quotation responses separately for each product.</p>
        </div>
    </header>

    <div class="ft-rfq-px-stats" aria-label="RFQ summary">
        <x-inquiries.product-rfq-stat label="products" :value="(int) ($stats['products'] ?? 0)" icon="product" />
        <x-inquiries.product-rfq-stat label="supplier assignments" :value="(int) ($stats['supplier_assignments'] ?? 0)" icon="suppliers" />
        <x-inquiries.product-rfq-stat label="invitations sent" :value="(int) ($stats['invitations_sent'] ?? 0)" icon="sent" />
        <x-inquiries.product-rfq-stat label="quotations received" :value="(int) ($stats['quotations_received'] ?? 0)" icon="quotes" />
    </div>

    <div class="ft-rfq-px-toolbar" role="search" aria-label="Filter product RFQ invitations">
        <label class="ft-rfq-px-search">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="11" cy="11" r="6.5"></circle><path d="m16 16 4 4"></path></svg>
            <input type="search" wire:model.live.debounce.300ms="rfqTableSearch" placeholder="Search products or suppliers" autocomplete="off">
        </label>

        <label class="ft-rfq-px-filter">
            <select wire:model.live="rfqEmailStatusFilter">
                @foreach(($workspace['status_filter_options'] ?? []) as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m7 9.5 5 5 5-5"></path></svg>
        </label>

        @if($canManage)
        <button type="button" class="ft-rfq-px-settings" wire:click="openRfqSettings">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21a2 2 0 1 1-4 0v-.09A1.7 1.7 0 0 0 8.5 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H3a2 2 0 1 1 0-4h.09A1.7 1.7 0 0 0 4.6 8.5a1.7 1.7 0 0 0-.34-1.88l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V3a2 2 0 1 1 4 0v.09A1.7 1.7 0 0 0 15.5 4.6a1.7 1.7 0 0 0 1.88-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9c.12.36.33.69.6 1 .3.27.68.42 1.09.4H21a2 2 0 1 1 0 4h-.09a1.7 1.7 0 0 0-1.51.6Z"></path></svg>
            <span>RFQ settings</span>
        </button>
        @endif
    </div>

    @if($selectedCount > 0)
        <div class="ft-rfq-px-selection">
            <div>
                <span class="ft-rfq-px-selection-check">✓</span>
                <strong>{{ $selectedCount }} {{ \Illuminate\Support\Str::plural('invitation', $selectedCount) }} selected across {{ $selectedProductCount }} {{ \Illuminate\Support\Str::plural('product', $selectedProductCount) }}</strong>
            </div>
            <div>
                @if($canManage)
                    <button type="button" wire:click="sendSelectedRfqEmails" wire:loading.attr="disabled" wire:target="sendSelectedRfqEmails">
                        <span wire:loading.remove wire:target="sendSelectedRfqEmails">Send {{ $selectedCount }} invitations</span>
                        <span wire:loading wire:target="sendSelectedRfqEmails">Sending…</span>
                    </button>
                @endif
                <button type="button" class="is-clear" wire:click="clearRfqSelection">Clear selection</button>
            </div>
        </div>
    @endif

    <div class="ft-rfq-px-products">
        @forelse($groups as $group)
            <x-inquiries.rfq-product-group
                :group="$group"
                :can-manage="$canManage"
                :can-edit-supplier="$canEditSuppliers"
                :selected-keys="$workspace['selected_keys'] ?? []"
            />
        @empty
            <div class="ft-rfq-px-empty">
                <strong>No product RFQ invitations found</strong>
                <span>Try another product, supplier or invitation-status filter.</span>
            </div>
        @endforelse
    </div>

    <footer class="ft-rfq-px-footer">
        <div class="ft-rfq-px-footer-note">
            <span>i</span>
            <p>Each invitation and supplier quotation is linked to one product. The same supplier can be invited for multiple products independently.</p>
        </div>
        <div class="ft-rfq-px-pagination">
            <span>Showing {{ $filteredProductCount }} {{ \Illuminate\Support\Str::plural('product', $filteredProductCount) }}</span>
            <button type="button" wire:click="setRfqTablePage({{ max(1, $currentPage - 1) }})" @disabled(! ($workspace['has_previous'] ?? false)) aria-label="Previous product page">‹</button>
            <span class="is-current">{{ $currentPage }}</span>
            <button type="button" wire:click="setRfqTablePage({{ min($lastPage, $currentPage + 1) }})" @disabled(! ($workspace['has_next'] ?? false)) aria-label="Next product page">›</button>
        </div>
    </footer>
</section>
