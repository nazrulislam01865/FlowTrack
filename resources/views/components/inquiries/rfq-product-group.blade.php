@props([
    'group',
    'canManage' => false,
    'canEditSupplier' => false,
    'selectedKeys' => [],
])

@php
    $rows = collect($group['supplier_rows'] ?? []);
    $productId = (int) ($group['product_id'] ?? 0);
    $quantity = (float) ($group['quantity'] ?? 0);
    $quantityDecimals = fmod(abs($quantity), 1.0) === 0.0 ? 0 : 2;
    $supplierCount = (int) ($group['supplier_count'] ?? $rows->count());
    $sentCount = (int) ($group['sent_count'] ?? 0);
    $failedCount = (int) ($group['failed_count'] ?? 0);
    $queuedCount = (int) ($group['queued_count'] ?? 0);
    $quotationCount = (int) ($group['quotation_count'] ?? 0);
    $headerAction = $group['header_action'] ?? ['type' => 'view', 'label' => 'View suppliers', 'tone' => 'secondary', 'supplier_ids' => []];
    $selectableKeys = $group['selectable_keys'] ?? [];
@endphp

<article
    class="ft-rfq-px-product {{ $failedCount > 0 ? 'has-failure' : '' }}"
    wire:key="rfq-product-group-{{ (int) ($group['item_id'] ?? 0) }}"
    x-data="{ expanded: @js((bool) ($group['expanded_default'] ?? false)) }"
>
    <header class="ft-rfq-px-product-head">
        <button type="button" class="ft-rfq-px-expand" x-on:click="expanded = !expanded" :aria-expanded="expanded.toString()" aria-label="Toggle supplier details">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" :class="{ 'is-open': expanded }"><path d="m8 10 4 4 4-4"></path></svg>
        </button>

        <span class="ft-rfq-px-index">{{ (int) ($group['index'] ?? 1) }}</span>

        <div class="ft-rfq-px-product-copy">
            <strong>{{ $group['name'] ?? 'Product' }}</strong>
            <span>
                @if(filled($group['code'] ?? null)){{ $group['code'] }} <b>·</b> @endif
                {{ number_format($quantity, $quantityDecimals) }} {{ $group['unit'] ?? 'units' }}
            </span>
        </div>

        <div class="ft-rfq-px-product-badges">
            <span class="ft-rfq-px-badge is-neutral">{{ $supplierCount }} {{ \Illuminate\Support\Str::plural('supplier', $supplierCount) }}</span>
            @if($sentCount > 0)<span class="ft-rfq-px-badge is-success">{{ $sentCount }} sent</span>@endif
            @if($failedCount > 0)<span class="ft-rfq-px-badge is-danger">{{ $failedCount }} failed</span>@endif
            @if($queuedCount > 0)<span class="ft-rfq-px-badge is-neutral">{{ $queuedCount }} queued</span>@endif
            <span class="ft-rfq-px-badge {{ $quotationCount > 0 ? 'is-success' : 'is-neutral' }}">{{ $quotationCount }} {{ \Illuminate\Support\Str::plural('quotation', $quotationCount) }}</span>
        </div>

        <div class="ft-rfq-px-product-actions">
            @if($canManage && $productId > 0)
                <button type="button" class="ft-rfq-px-add-supplier" wire:click="openRfqSupplierPicker({{ $productId }})" wire:loading.attr="disabled" wire:target="openRfqSupplierPicker({{ $productId }})">Add supplier</button>
            @endif

            @if(($headerAction['type'] ?? '') === 'send' && $canManage)
                <button
                    type="button"
                    class="ft-rfq-px-product-action is-{{ $headerAction['tone'] ?? 'primary' }}"
                    wire:click='sendRfqProductEmails(@json($headerAction['supplier_ids'] ?? []))'
                    wire:loading.attr="disabled"
                    wire:target="sendRfqProductEmails"
                >{{ $headerAction['label'] ?? 'Send invitation' }}</button>
            @else
                <button type="button" class="ft-rfq-px-product-action is-secondary" x-on:click="expanded = true">{{ $headerAction['label'] ?? 'View suppliers' }}</button>
            @endif
        </div>

        <button type="button" class="ft-rfq-px-collapse" x-on:click="expanded = !expanded" aria-label="Toggle supplier details">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" :class="{ 'is-open': expanded }"><path d="m8 10 4 4 4-4"></path></svg>
        </button>
    </header>

    <div class="ft-rfq-px-product-body" x-cloak x-show="expanded" x-transition.opacity.duration.120ms>
        @if($failedCount > 0)
            <div class="ft-rfq-px-product-alert">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3.5 21 19H3z"></path><path d="M12 9v4M12 16h.01"></path></svg>
                <span>{{ $failedCount }} {{ \Illuminate\Support\Str::plural('invitation', $failedCount) }} failed for this product. Review the error and retry.</span>
            </div>
        @endif

        @if($rows->isNotEmpty())
            <div class="ft-rfq-px-table-panel">
                <div class="ft-rfq-px-table-wrap">
                    <table class="ft-rfq-px-table">
                    <thead>
                        <tr>
                            <th class="ft-rfq-px-check-col">
                                <button
                                    type="button"
                                    class="ft-rfq-px-master-check {{ ($group['all_selectable_selected'] ?? false) ? 'is-checked' : '' }}"
                                    wire:click='toggleRfqProductSelection(@json($selectableKeys))'
                                    @disabled($selectableKeys === [])
                                    aria-label="Select suppliers for {{ $group['name'] ?? 'product' }}"
                                >
                                    @if($group['all_selectable_selected'] ?? false)<span>✓</span>@endif
                                </button>
                            </th>
                            <th>Supplier</th>
                            <th>Email</th>
                            <th>Invitation status</th>
                            <th>Quotation</th>
                            <th>Last activity</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            <x-inquiries.rfq-product-supplier-row
                                :row="$row"
                                :can-manage="$canManage"
                                :can-edit-supplier="$canEditSupplier"
                                :selected-keys="$selectedKeys"
                            />
                        @endforeach
                    </tbody>
                    </table>
                </div>
                <footer class="ft-rfq-px-product-note">These invitations request pricing only for {{ $group['name'] ?? 'this product' }}.</footer>
            </div>
        @else
            <div class="ft-rfq-px-product-empty">
                <span>No suppliers match the current filters for this product.</span>
                @if($canManage && $productId > 0)
                    <button type="button" wire:click="openRfqSupplierPicker({{ $productId }})">Add supplier</button>
                @endif
            </div>
        @endif
    </div>
</article>
