@props(['task', 'payload' => [], 'preview' => []])
@php
    $poRows = (array) ($preview['rows'] ?? []);
    $poProducts = (array) ($preview['products'] ?? []);
    $generated = count($poRows) > 0;
    $mode = $payload['supplier_po_mode'] ?? 'single';
    $selectedItemIds = array_map('intval', (array) ($payload['supplier_po_item_ids'] ?? []));
    $selectedPoId = (int) ($payload['supplier_po_selected_id'] ?? ($poRows[0]['id'] ?? 0));
    $selectedPo = collect($poRows)->firstWhere('id', $selectedPoId) ?: ($poRows[0] ?? null);
@endphp
<div class="ft-supplier-po" aria-label="Supplier purchase orders">
    @unless($generated)
        <p class="ft-prototype-modal-copy">Choose how you want to create the Purchase Order for this order.</p>
        <div class="ft-prototype-choice-grid ft-supplier-po-choices" role="group" aria-label="Purchase Order type">
            <button type="button" class="ft-supplier-po-option {{ $mode === 'single' ? 'is-selected' : '' }}" wire:click="chooseSupplierPurchaseOrderMode('single')" aria-pressed="{{ $mode === 'single' ? 'true' : 'false' }}">
                <span class="ft-supplier-po-option-symbol" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h8"/></svg>
                </span>
                <span class="ft-supplier-po-option-label"><strong>Single Purchase Order</strong><small>One PDF with all {{ count($poProducts) }} {{ count($poProducts) === 1 ? 'product' : 'products' }}</small></span>
                <span class="ft-supplier-po-option-check" aria-hidden="true">
                    @if($mode === 'single')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="m5 12 4 4L19 6"/></svg>@endif
                </span>
            </button>
            <button type="button" class="ft-supplier-po-option {{ $mode === 'split' ? 'is-selected' : '' }}" wire:click="chooseSupplierPurchaseOrderMode('split')" aria-pressed="{{ $mode === 'split' ? 'true' : 'false' }}" @if(count($poProducts) < 2) disabled title="At least two products are needed to split a PO" @endif>
                <span class="ft-supplier-po-option-symbol" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="11" height="15" rx="1.5"/><path d="M8 8h4M8 12h4M17 8h1a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2v-1"/></svg>
                </span>
                <span class="ft-supplier-po-option-label"><strong>Split Purchase Orders</strong><small>Separate PDFs for selected products</small></span>
                <span class="ft-supplier-po-option-check" aria-hidden="true">
                    @if($mode === 'split')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="m5 12 4 4L19 6"/></svg>@endif
                </span>
            </button>
        </div>
        @if($mode === 'split')
            <section class="ft-supplier-po-product-section" aria-label="Select products to split">
                <div class="ft-supplier-po-section-heading">
                    <strong>Which products need separate POs?</strong>
                    <span>{{ count($selectedItemIds) }} of {{ count($poProducts) }} selected</span>
                </div>
                <p>Select the products that should each have their own PO. Other products will be grouped by supplier automatically.</p>
                <div class="ft-supplier-po-product-list">
                    @foreach($poProducts as $product)
                        <label wire:key="supplier-po-select-{{ $product['id'] }}" class="ft-supplier-po-product {{ in_array((int) $product['id'], $selectedItemIds, true) ? 'is-selected' : '' }}">
                            <input type="checkbox" value="{{ $product['id'] }}" wire:model.live="orderWorkflowActionPayload.supplier_po_item_ids">
                            <span class="ft-supplier-po-product-name"><b>{{ $product['name'] }}</b><small>{{ number_format($product['quantity']) }} pcs · {{ $product['supplier'] }}</small></span>
                        </label>
                    @endforeach
                </div>
                @error('orderWorkflowActionPayload.supplier_po_item_ids')<p class="validation-error" role="alert">{{ $message }}</p>@enderror
            </section>
        @endif
        <details class="ft-supplier-po-extra">
            <summary><span><strong>Carton marks &amp; payment details</strong><small>Optional — leave anything unknown blank</small></span><span class="ft-supplier-po-chevron" aria-hidden="true">⌄</span></summary>
            <div class="ft-supplier-po-extra-body">
                <div class="ft-supplier-po-extra-heading"><strong>4. Carton marks requirement</strong><small>These values appear in section 4 of the supplied Excel template.</small></div>
                <div class="ft-supplier-po-extra-grid">
                    @foreach(['carton_buyer' => 'Carton buyer', 'carton_style' => 'Style number', 'carton_colour' => 'Colour', 'carton_size' => 'Size', 'carton_gross_weight' => 'Gross weight (kg)', 'carton_net_weight' => 'Net weight (kg)', 'carton_measurement' => 'Carton measurement (cm)', 'carton_other' => 'Other carton notes'] as $field => $label)
                        <label class="ft-supplier-po-extra-field"><span>{{ $label }}</span><input type="text" maxlength="255" wire:model.blur="orderWorkflowActionPayload.supplier_po_template.{{ $field }}" placeholder="Optional"></label>
                    @endforeach
                    <label class="ft-supplier-po-extra-field"><span>Carton mark quantity (PCS)</span><input type="text" maxlength="20" wire:model.blur="orderWorkflowActionPayload.supplier_po_template.carton_qty" placeholder="Leave blank if unknown"></label>
                    <label class="ft-supplier-po-extra-field"><span>Mark type</span><select wire:model="orderWorkflowActionPayload.supplier_po_template.carton_mark_type"><option value="">Not specified</option><option value="side">Side mark only</option><option value="two">Two sides</option><option value="four">Four sides</option><option value="barcode">Barcode mark</option><option value="fragile">Fragile / this side up</option></select></label>
                    <label class="ft-supplier-po-extra-field"><span>Outer carton strength</span><select wire:model="orderWorkflowActionPayload.supplier_po_template.carton_strength"><option value="">Not specified</option><option value="5ply">5-ply</option><option value="3ply">3-ply</option><option value="export">Strong export carton</option></select></label>
                    <label class="ft-supplier-po-extra-field"><span>Inner packing</span><select wire:model="orderWorkflowActionPayload.supplier_po_template.inner_packing"><option value="">Not specified</option><option value="polybag">Polybag per piece</option><option value="tissue">Tissue paper</option><option value="desiccant">Desiccant</option></select></label>
                    <label class="ft-supplier-po-extra-field"><span>Pieces per inner carton</span><input type="text" maxlength="20" wire:model.blur="orderWorkflowActionPayload.supplier_po_template.pieces_per_inner" placeholder="Optional"></label>
                    <label class="ft-supplier-po-extra-field"><span>Outer carton other</span><input type="text" maxlength="100" wire:model.blur="orderWorkflowActionPayload.supplier_po_template.carton_strength_other" placeholder="Optional"></label>
                    <label class="ft-supplier-po-extra-field"><span>Inner packing other</span><input type="text" maxlength="100" wire:model.blur="orderWorkflowActionPayload.supplier_po_template.inner_packing_other" placeholder="Optional"></label>
                </div>
                <div class="ft-supplier-po-extra-heading"><strong>5. Payment method</strong><small>Currency is taken from the Order. Leave unspecified terms and bank details blank.</small></div>
                <div class="ft-supplier-po-extra-grid">
                    <label class="ft-supplier-po-extra-field"><span>Payment method</span><select wire:model.live="orderWorkflowActionPayload.supplier_po_template.payment_method"><option value="">Not specified</option><option value="tt">T/T (bank transfer)</option><option value="lc">L/C at sight</option><option value="dp">D/P</option><option value="other">Other</option></select></label>
                    <label class="ft-supplier-po-extra-field"><span>Payment terms</span><select wire:model="orderWorkflowActionPayload.supplier_po_template.payment_term"><option value="">Not specified</option><option value="30_70">30% deposit / 70% before shipment</option><option value="50_50">50% / 50%</option><option value="net30">Net 30 days</option><option value="net60">Net 60 days</option></select></label>
                    @if(data_get($payload,'supplier_po_template.payment_method') === 'tt')
                        <label class="ft-supplier-po-extra-field"><span>Deposit (%)</span><input type="text" maxlength="5" wire:model.blur="orderWorkflowActionPayload.supplier_po_template.deposit_percent" placeholder="Optional"></label>
                        <label class="ft-supplier-po-extra-field"><span>Balance (%)</span><input type="text" maxlength="5" wire:model.blur="orderWorkflowActionPayload.supplier_po_template.balance_percent" placeholder="Optional"></label>
                    @elseif(data_get($payload,'supplier_po_template.payment_method') === 'other')
                        <label class="ft-supplier-po-extra-field"><span>Other payment method</span><input type="text" maxlength="100" wire:model.blur="orderWorkflowActionPayload.supplier_po_template.payment_other" placeholder="Specify method"></label>
                    @endif
                    @foreach(['beneficiary' => 'Beneficiary', 'bank_name' => 'Bank name', 'bank_address' => 'Bank address', 'account_number' => 'Account number', 'swift_code' => 'SWIFT code'] as $field => $label)
                        <label class="ft-supplier-po-extra-field"><span>{{ $label }}</span><input type="text" maxlength="255" wire:model.blur="orderWorkflowActionPayload.supplier_po_template.{{ $field }}" placeholder="Optional"></label>
                    @endforeach
                </div>
                <p class="ft-supplier-po-extra-hint">The supplied PO template stays unchanged. Unknown carton, payment, and bank details remain blank in the PDF.</p>
                @error('orderWorkflowActionPayload.supplier_po_template')<p class="validation-error" role="alert">{{ $message }}</p>@enderror
            </div>
        </details>
        @error('orderWorkflowActionPayload.supplier_po_mode')<p class="validation-error" role="alert">{{ $message }}</p>@enderror
        @error('orderWorkflowActionPayload')<p class="validation-error" role="alert">{{ $message }}</p>@enderror
        <footer class="ft-supplier-po-actions">
            <x-ui.button variant="secondary" size="sm" wire:click="closeOrderWorkflowAction">Cancel</x-ui.button>
            <x-ui.button variant="primary" size="sm" wire:click="createSupplierPurchaseOrders" wire:loading.attr="disabled" wire:target="createSupplierPurchaseOrders" :disabled="$mode === 'split' && count($selectedItemIds) === 0">
                <span wire:loading.remove wire:target="createSupplierPurchaseOrders">Generate {{ $mode === 'split' ? 'Split POs' : 'Single PO' }}</span>
                <span wire:loading wire:target="createSupplierPurchaseOrders">Generating...</span>
            </x-ui.button>
        </footer>
    @else
        <div class="ft-supplier-po-ready"><span aria-hidden="true">✓</span><div><strong>{{ count($poRows) }} Purchase Order{{ count($poRows) === 1 ? '' : 's' }} ready</strong><small>PDF{{ count($poRows) === 1 ? '' : 's' }} saved under this task. Email is optional; you can complete without sending.</small></div></div>
        @if(count($poRows) > 1)
            <nav class="ft-supplier-po-files" aria-label="Generated Purchase Orders">
                @foreach($poRows as $po)
                    <button type="button" wire:key="supplier-po-tab-{{ $po['id'] }}" wire:click="selectSupplierPurchaseOrder({{ $po['id'] }})" class="ft-supplier-po-file {{ (int) $selectedPo['id'] === (int) $po['id'] ? 'is-active' : '' }}" aria-current="{{ (int) $selectedPo['id'] === (int) $po['id'] ? 'true' : 'false' }}">
                        <span class="ft-supplier-po-file-icon" aria-hidden="true">PDF</span><span><b>{{ $po['number'] }}</b><small>{{ $po['supplier'] }}</small></span>
                        <span class="ft-supplier-po-dot {{ $po['status'] === 'sent' ? 'is-sent' : ($po['status'] === 'failed' ? 'is-failed' : '') }}" aria-label="{{ $po['status'] === 'sent' ? 'Sent' : ($po['status'] === 'failed' ? 'Failed' : 'Not sent') }}"></span>
                    </button>
                @endforeach
            </nav>
        @endif
        @if($selectedPo)
            <div class="ft-supplier-po-send" wire:key="supplier-po-email-{{ $selectedPo['id'] }}">
                <div class="ft-supplier-po-document">
                    <div class="ft-supplier-po-pdf-preview"><span class="ft-supplier-po-pdf-badge">PDF</span><strong>{{ $selectedPo['pdf_name'] }}</strong><small>Purchase Order · {{ $selectedPo['number'] }}</small><span class="ft-supplier-po-pdf-caption">{{ $selectedPo['status'] === 'sent' ? 'Email sent with this PDF attached.' : 'This PDF is attached automatically if you choose Send.' }}</span></div>
                    <div class="ft-supplier-po-downloads">
                        @if($selectedPo['document_id'])
                            <a href="{{ route('documents.open', $selectedPo['document_id']) }}" target="_blank" rel="noopener">Open PDF ↗</a>
                            <a href="{{ route('documents.download', $selectedPo['document_id']) }}">Download</a>
                        @endif
                    </div>
                </div>
                <div class="ft-supplier-po-email-details">
                    <div class="ft-supplier-po-meta"><div><span>SUPPLIER</span><strong>{{ $selectedPo['supplier'] }}</strong></div><div><span>STATUS</span><strong class="{{ $selectedPo['status'] === 'sent' ? 'ft-supplier-po-sent-text' : '' }}">{{ $selectedPo['status'] === 'sent' ? 'Sent' : ($selectedPo['status'] === 'failed' ? 'Failed - retry' : 'Not sent') }}</strong></div></div>
                    <div class="ft-supplier-po-meta ft-supplier-po-items"><div><span>PRODUCTS</span><strong>{{ $selectedPo['items'] }}</strong></div></div>
                    @if($selectedPo['status'] !== 'sent' && (!$task->completed_at || $selectedPo['status'] === 'failed'))
                    <section class="ft-supplier-po-recipient-section" aria-label="Email recipients">
                        <strong>To <small>(optional)</small></strong>
                        @foreach((array) ($payload['supplier_po_recipients'][$selectedPo['id']] ?? ['']) as $recipientKey => $recipientEmail)
                            <div class="ft-supplier-po-recipient" wire:key="supplier-po-recipient-{{ $selectedPo['id'] }}-{{ $recipientKey }}">
                                <input type="email" inputmode="email" maxlength="254" wire:model.blur="orderWorkflowActionPayload.supplier_po_recipients.{{ $selectedPo['id'] }}.{{ $recipientKey }}" value="{{ $recipientEmail }}" placeholder="Enter email address" aria-label="Recipient {{ $loop->iteration }}" autocomplete="off" autocapitalize="off" spellcheck="false">
                                @unless($loop->first)<button type="button" wire:click="removeSupplierPoRecipient({{ $selectedPo['id'] }}, {{ $recipientKey }})" aria-label="Remove recipient">×</button>@endunless
                            </div>
                        @endforeach
                        <button type="button" class="ft-supplier-po-add-recipient" wire:click="addSupplierPoRecipient({{ $selectedPo['id'] }})" @if(count((array) ($payload['supplier_po_recipients'][$selectedPo['id']] ?? [])) >= 20) disabled @endif>+ Add recipient</button>
                        @error('orderWorkflowActionPayload.supplier_po_emails.'.$selectedPo['id'])<p class="validation-error" role="alert">{{ $message }}</p>@enderror
                    </section>
                    @elseif($selectedPo['status'] === 'sent')
                        <p class="ft-supplier-po-delivered" role="status">Email sent successfully{{ $selectedPo['sent_at'] ? ' · '.$selectedPo['sent_at'] : '' }}. No resend is needed.</p>
                    @else
                        <p class="ft-supplier-po-delivered">This task is completed. The PO PDF is available above.</p>
                    @endif
                    <div class="ft-supplier-po-message"><span>SUBJECT</span><p>{{ $selectedPo['subject'] }}</p><span>EMAIL BODY</span><div class="ft-supplier-po-body">{{ $selectedPo['body'] }}</div></div>
                    @if($selectedPo['status'] !== 'sent' && (!$task->completed_at || $selectedPo['status'] === 'failed'))
                        <div class="ft-supplier-po-send-action">
                            <small>{{ $selectedPo['status'] === 'failed' ? 'Delivery failed. You can retry or complete without email.' : 'Add a recipient to send, or complete without email.' }}</small>
                            <x-ui.button variant="primary" wire:click="sendSupplierPurchaseOrder({{ $selectedPo['id'] }})" wire:loading.attr="disabled" wire:target="sendSupplierPurchaseOrder({{ $selectedPo['id'] }})">
                                <span wire:loading.remove wire:target="sendSupplierPurchaseOrder({{ $selectedPo['id'] }})">{{ $selectedPo['status'] === 'failed' ? 'Resend PO' : 'Send PO' }}</span>
                                <span wire:loading wire:target="sendSupplierPurchaseOrder({{ $selectedPo['id'] }})">Sending...</span>
                            </x-ui.button>
                        </div>
                    @endif
                </div>
            </div>
        @endif
        <footer class="ft-supplier-po-actions">
            @if(!$task->completed_at)
                <x-ui.button variant="secondary" wire:click="closeOrderWorkflowAction">Close</x-ui.button>
                @if(count($poRows) > 1 && collect($poRows)->contains(fn (array $po) => in_array($po['status'], ['not_sent', 'failed'], true)))
                    <x-ui.button variant="secondary" wire:click="sendAllSupplierPurchaseOrders" wire:loading.attr="disabled" wire:target="sendAllSupplierPurchaseOrders">Send all POs</x-ui.button>
                @endif
                <x-ui.button variant="primary" wire:click="completeSupplierPurchaseOrdersWithoutEmail" wire:loading.attr="disabled" wire:target="completeSupplierPurchaseOrdersWithoutEmail">
                    <span wire:loading.remove wire:target="completeSupplierPurchaseOrdersWithoutEmail">{{ collect($poRows)->contains(fn (array $po) => $po['status'] === 'sent') ? 'Complete (skip remaining emails)' : 'Complete without email' }}</span>
                    <span wire:loading wire:target="completeSupplierPurchaseOrdersWithoutEmail">Completing...</span>
                </x-ui.button>
            @else
                <x-ui.button variant="secondary" wire:click="closeOrderWorkflowAction">Done</x-ui.button>
            @endif
        </footer>
    @endunless
</div>
