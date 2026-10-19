@props([
    'item' => [],
    'index' => 0,
    'detail' => null,
    'defaultSupplier' => null,
    'rfqState' => [],
    'rfqSuppliers' => collect(),
])

@php
    $name = (string) ($detail?->name ?? ($item['product'] ?? 'Product'));
    $code = (string) ($detail?->productDisplayCode() ?? ($detail?->code ?? ''));
    $imageUrl = $detail?->productImageUrl();
    $supplierCount = collect($rfqSuppliers)->count();
    $sendOnCreate = (bool) ($rfqState['send_on_create'] ?? true);
    $quantityError = $errors->first("createProductRows.$index.quantity");
    $dueError = $errors->first("createProductRfqRows.$index.due_date");
    $messageError = $errors->first("createProductRfqRows.$index.message");
    $supplierError = $errors->first("createProductRfqRows.$index.supplier_ids");
@endphp

<article class="ft-ipr-card">
    <div class="ft-ipr-card-head">
        <div class="ft-ipr-product-main">
            <span class="ft-ipr-product-image">
                @if($imageUrl)
                    <img src="{{ $imageUrl }}" alt="" loading="lazy" decoding="async" data-ft-image-fallback="icon">
                @else
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M20 12 12 20 4 12V4h8l8 8Z"/><circle cx="8.5" cy="8.5" r="1.2"/></svg>
                @endif
            </span>
            <span class="ft-ipr-product-copy">
                <strong>{{ $name }}</strong>
                <small>Product code {{ $code ?: '—' }}</small>
            </span>
        </div>

        <div class="ft-ipr-quantity-wrap">
            <label for="inquiry-product-qty-{{ $index }}">Quantity</label>
            <div class="ft-ipr-quantity-controls">
                <input id="inquiry-product-qty-{{ $index }}" type="number" min="1" max="999999999" wire:model.live.debounce.300ms="createProductRows.{{ $index }}.quantity" aria-label="Quantity for {{ $name }}">
                <select wire:model.live="createProductRows.{{ $index }}.unit" aria-label="Unit for {{ $name }}">
                    <option value="units">Units</option>
                    <option value="pcs">Pieces</option>
                    <option value="sets">Sets</option>
                    <option value="pairs">Pairs</option>
                </select>
            </div>
            @if($quantityError)<small class="validation-error">{{ $quantityError }}</small>@endif
        </div>

        <div class="ft-ipr-card-status">
            <span class="ft-ipr-supplier-count">{{ $supplierCount }} {{ \Illuminate\Support\Str::plural('supplier', $supplierCount) }}</span>
            <span class="ft-ipr-send-badge {{ $sendOnCreate ? 'is-send' : 'is-draft' }}">{{ $sendOnCreate ? 'Invite on create' : 'Draft only' }}</span>
        </div>

        <div class="ft-ipr-card-actions">
            <button type="button" class="ft-ipr-duplicate" wire:click="duplicateCreateProductRow({{ $index }})" aria-label="Duplicate {{ $name }}">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="6" y="6" width="9" height="9" rx="1.5"/><path d="M4 12H3.5A1.5 1.5 0 0 1 2 10.5v-7A1.5 1.5 0 0 1 3.5 2h7A1.5 1.5 0 0 1 12 3.5V4"/></svg>
                <span>Duplicate</span>
            </button>
            <button type="button" class="ft-ipr-delete" wire:click="removeCreateProductRow({{ $index }})" aria-label="Remove {{ $name }}">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M3.5 5.5h13M8 8.5v5M12 8.5v5M6 5.5l.6 10h6.8l.6-10M7.5 5.5l.7-2h3.6l.7 2"/></svg>
            </button>
        </div>
    </div>

    <div class="ft-ipr-card-body">
        <section class="ft-ipr-supplier-pane">
            <h3>Suppliers for this product</h3>
            <div class="ft-ipr-supplier-picker" x-data>
                <div x-ref="supplierPicker">
                    <x-ui.search-select
                        class="ft-ipr-supplier-search-select"
                        label="Supplier"
                        type="suppliers"
                        context="create-inquiry"
                        property="create-product-rfq-supplier:{{ $index }}"
                        value=""
                        placeholder="Search and add suppliers"
                        search-placeholder="Search supplier name or email"
                        :selected-label="null"
                        :clearable="false"
                        action="addCreateProductRfqSupplierFromSelector"
                        :hide-label="true"
                        :fixed-menu="true"
                        :menu-width="420"
                        wire:key="create-product-rfq-picker-{{ $index }}-{{ $supplierCount }}"
                    />
                </div>

                <div class="ft-ipr-supplier-list">
                    @forelse($rfqSuppliers as $supplier)
                        @php
                            $supplierName = (string) data_get($supplier, 'name', 'Supplier');
                            $supplierShortCode = trim((string) data_get($supplier, 'short_code', ''));
                            $supplierLabel = $supplierShortCode !== '' ? $supplierShortCode : $supplierName;
                            $supplierEmail = trim((string) data_get($supplier, 'email', ''));
                            $words = preg_split('/\s+/u', trim($supplierName)) ?: [];
                            $initials = strtoupper(mb_substr(implode('', array_map(fn ($word) => mb_substr($word, 0, 1), $words)), 0, 2)) ?: 'S';
                            $emailReady = (bool) data_get($supplier, 'email_ready', false);
                        @endphp
                        <div class="ft-ipr-supplier-row" wire:key="create-product-rfq-selected-{{ $index }}-{{ (int) data_get($supplier, 'id') }}">
                            <span class="ft-ipr-supplier-avatar">{{ $initials }}</span>
                            <span class="ft-ipr-supplier-copy">
                                <strong>{{ $supplierLabel }}</strong>
                                <small>{{ $supplierEmail !== '' ? $supplierEmail : 'No email configured' }}</small>
                            </span>
                            <span class="ft-ipr-email-badge {{ $emailReady ? '' : 'is-muted' }}">{{ $emailReady ? 'Email ready' : 'No email' }}</span>
                            <button type="button" wire:click="removeCreateProductRfqSupplier({{ $index }}, {{ (int) data_get($supplier, 'id') }})">Remove</button>
                        </div>
                    @empty
                        <div class="ft-ipr-no-supplier">No supplier selected yet.</div>
                    @endforelse
                </div>

                <button type="button" class="ft-ipr-add-supplier" x-on:click="$refs.supplierPicker?.querySelector('[aria-haspopup=listbox]')?.click()">
                    <span aria-hidden="true">+</span> Add supplier
                </button>
            </div>
            @if($supplierError)<small class="validation-error">{{ $supplierError }}</small>@endif
            <p class="ft-ipr-product-supplier-note">These suppliers will receive an RFQ only for {{ $name }}.</p>
        </section>

        <section class="ft-ipr-rfq-pane">
            <h3>Product RFQ settings</h3>
            <label class="ft-ipr-rfq-field">
                <span>Quotation due</span>
                <input type="date" wire:model.live="createProductRfqRows.{{ $index }}.due_date">
                @if($dueError)<small class="validation-error">{{ $dueError }}</small>@endif
            </label>
            <label class="ft-ipr-rfq-field">
                <span>Message</span>
                <textarea rows="3" wire:model.live.debounce.350ms="createProductRfqRows.{{ $index }}.message"></textarea>
                @if($messageError)<small class="validation-error">{{ $messageError }}</small>@endif
            </label>

            <label class="ft-ipr-toggle-row">
                <input type="checkbox" wire:model.live="createProductRfqRows.{{ $index }}.send_on_create">
                <span class="ft-ipr-toggle" aria-hidden="true"><i></i></span>
                <b>Send invitations when inquiry is created</b>
            </label>
            <p class="ft-ipr-send-summary">
                @if($sendOnCreate)
                    <strong>{{ $supplierCount }}</strong> {{ \Illuminate\Support\Str::plural('invitation', $supplierCount) }} will be sent
                @else
                    Invitation saved as draft
                @endif
            </p>
        </section>
    </div>
</article>
