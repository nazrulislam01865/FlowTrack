@props(['job','contacts'=>collect(),'summary'=>[],'invoiceTypes'=>collect(),'currencies'=>collect(),'paymentTerms'=>collect(),'invoiceLineItems'=>[],'invoiceTaxRate'=>'0','invoiceCurrency'=>'USD','invoiceType'=>'Final invoice','invoiceRemoteAreaCharge'=>0,'invoiceRemoteAreaName'=>'','invoiceRemoteAreaPostalCode'=>'','invoiceSupportingDocument'=>null])
@php
    $itemsSubtotal = collect($invoiceLineItems)->sum(fn($item) => max(0, (float)($item['quantity'] ?? 0)) * max(0, (float)($item['unit_price'] ?? 0)));
    $remoteAreaCharge = max(0, (float) $invoiceRemoteAreaCharge);
    $subtotal = $itemsSubtotal + $remoteAreaCharge;
    $taxRate = max(0, min(100, (float)$invoiceTaxRate));
    $taxAmount = $subtotal * ($taxRate / 100);
    // The current invoice total is based only on its own line items, the
    // authoritative Remote Area charge (when applicable), and tax. Payments are
    // handled separately through Record Payment.
    $total = $subtotal + $taxAmount;
    $currencyValue = strtoupper(trim((string) $invoiceCurrency));
    $currencyPrefix = match ($currencyValue) {
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'CNY', 'RMB' => '¥',
        'BDT' => '৳',
        'JPY' => '¥',
        'KRW' => '₩',
        'INR' => '₹',
        'CAD' => 'C$',
        'AUD' => 'A$',
        default => $currencyValue !== '' ? $currencyValue.' ' : '',
    };

    $currencyMaster = app(\App\Services\MasterDataService::class);
    $invoiceCurrencies = collect($currencies)
        ->map(function ($option) use ($currencyMaster) {
            $value = $currencyMaster->currencyValue($option);
            return [
                'value' => $value,
                'label' => trim((string) ($option->name ?? '')) ?: $value,
            ];
        })
        ->filter(fn ($option) => $option['value'] !== '')
        ->unique('value')
        ->values();

    // One dropdown option per real client contact. The legacy client contact is
    // shown only when there are no structured contacts, avoiding duplicate rows
    // for the same person.
    $billingContacts = collect($contacts)
        ->filter(fn ($contact) => !empty($contact->id))
        ->unique(function ($contact) {
            $email = strtolower(trim((string) ($contact->email ?? '')));
            if ($email !== '') return 'email:'.$email;
            return 'contact:'.strtolower(trim((string) ($contact->name ?? ''))).'|'.trim((string) ($contact->phone ?? ''));
        })
        ->values();
    $billingContactNameCounts = $billingContacts
        ->groupBy(fn ($contact) => strtolower(trim((string) ($contact->name ?? ''))))
        ->map->count();
@endphp
<div class="ft-finance-modal-backdrop" wire:key="create-invoice-modal" wire:click.self="closeCreateInvoice">
    <section class="ft-finance-modal ft-create-invoice-modal" data-ft-feedback-scope="form" role="dialog" aria-modal="true" aria-labelledby="createInvoiceTitle">
        <header class="ft-finance-modal-head">
            <div><h2 id="createInvoiceTitle">Create invoice</h2><p>Create and issue an invoice for this order.</p></div>
            <button type="button" wire:click="closeCreateInvoice" aria-label="Close">×</button>
        </header>

        @error('invoiceForm')<div class="ft-finance-form-alert">{{ $message }}</div>@enderror

        <div class="ft-invoice-form-grid">
            <label><span>Invoice type <b>*</b></span><select wire:model.live="invoiceType"><option value="">Select invoice type</option>@foreach($invoiceTypes as $option)<option value="{{ $option->name }}">{{ $option->name }}</option>@endforeach</select>@error('invoiceType')<small class="error">{{ $message }}</small>@enderror</label>
            <label><span>Currency <b>*</b></span><select wire:model.live="invoiceCurrency"><option value="">Select currency</option>@foreach($invoiceCurrencies as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select>@error('invoiceCurrency')<small class="error">{{ $message }}</small>@enderror</label>
            <label><span>Issue date <b>*</b></span><input type="date" wire:model.live="invoiceIssueDate">@error('invoiceIssueDate')<small class="error">{{ $message }}</small>@enderror</label>
            <label><span>Payment terms <b>*</b></span><select wire:model.live="invoicePaymentTerms"><option value="">Select payment terms</option>@foreach($paymentTerms as $option)<option value="{{ $option->name }}">{{ $option->name }}</option>@endforeach</select>@error('invoicePaymentTerms')<small class="error">{{ $message }}</small>@enderror</label>
            <label><span>Due date <b>*</b></span><input type="date" wire:model="invoiceDueDate">@error('invoiceDueDate')<small class="error">{{ $message }}</small>@enderror</label>
            <label><span>Billing contact <b>*</b></span><select wire:model="invoiceBillingContactId">
                @if($billingContacts->isNotEmpty())
                    <option value="">Select billing contact</option>
                    @foreach($billingContacts as $contact)
                        @php
                            $contactName = trim((string) ($contact->name ?? '')) ?: trim((string) ($contact->email ?? '')) ?: 'Contact';
                            $sameNameCount = (int) ($billingContactNameCounts[strtolower(trim((string) ($contact->name ?? '')))] ?? 0);
                            $contactLabel = $contactName;
                            if ($sameNameCount > 1 && trim((string) ($contact->email ?? '')) !== '') $contactLabel .= ' · '.trim((string) $contact->email);
                        @endphp
                        <option value="{{ $contact->id }}">{{ $contactLabel }}</option>
                    @endforeach
                @else
                    <option value="">{{ $job->client?->contact_name ?: $job->client?->email ?: 'Primary client contact' }}</option>
                @endif
            </select>@error('invoiceBillingContactId')<small class="error">{{ $message }}</small>@enderror</label>
        </div>

        <section class="ft-invoice-items-box">
            <h3>Invoice items</h3>
            <div class="ft-invoice-item-head"><span>Description</span><span>Quantity</span><span>Unit price</span><span>Amount</span><span>action</span></div>
            @foreach($invoiceLineItems as $index => $line)
                @php($lineAmount = max(0,(float)($line['quantity'] ?? 0)) * max(0,(float)($line['unit_price'] ?? 0)))
                <div class="ft-invoice-item-row" wire:key="invoice-line-{{ $index }}">
                    <div><input type="text" wire:model.live.debounce.250ms="invoiceLineItems.{{ $index }}.description" aria-label="Description">@error('invoiceLineItems.'.$index.'.description')<small class="error">{{ $message }}</small>@enderror</div>
                    <div><input type="number" min="0.01" step="0.01" wire:model.live.debounce.250ms="invoiceLineItems.{{ $index }}.quantity" aria-label="Quantity">@error('invoiceLineItems.'.$index.'.quantity')<small class="error">{{ $message }}</small>@enderror</div>
                    <div class="money-input"><span>{{ $currencyPrefix }}</span><input type="number" min="0" step="0.01" wire:model.live.debounce.250ms="invoiceLineItems.{{ $index }}.unit_price" aria-label="Unit price">@error('invoiceLineItems.'.$index.'.unit_price')<small class="error">{{ $message }}</small>@enderror</div>
                    <strong>{{ $currencyPrefix }}{{ number_format($lineAmount, 2) }}</strong>
                    <button type="button" wire:click="removeInvoiceLineItem({{ $index }})" aria-label="Remove line item" @disabled(count($invoiceLineItems) <= 1)>
                        <svg viewBox="0 0 24 24"><path d="M8 8v10M12 8v10M16 8v10M5 5h14M9 5V3h6v2M7 5l1 16h8l1-16"></path></svg>
                    </button>
                </div>
            @endforeach
            @error('invoiceLineItems')<small class="error ft-line-error">{{ $message }}</small>@enderror
            <button type="button" class="ft-add-invoice-line" wire:click="addInvoiceLineItem">＋ Add line item</button>
        </section>

        <div class="ft-invoice-lower-grid">
            <div class="ft-invoice-extra-fields">
                <label><span>Purchase order reference</span><input type="text" wire:model="invoicePurchaseOrderReference" placeholder="PO-2026-4481">@error('invoicePurchaseOrderReference')<small class="error">{{ $message }}</small>@enderror</label>
                <label><span>Notes / payment instructions</span><textarea wire:model="invoiceNotes"></textarea>@error('invoiceNotes')<small class="error">{{ $message }}</small>@enderror</label>
                <label class="ft-invoice-upload"><span class="ft-paperclip" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8.5 12.5 14.8 6.2a3.1 3.1 0 1 1 4.4 4.4l-8.1 8.1a5 5 0 0 1-7.1-7.1l8.2-8.2"></path></svg></span><span class="ft-upload-copy">Attach supporting document</span><span class="ft-upload-browse">Browse</span><input type="file" wire:model="invoiceSupportingDocument" accept="{{ \App\Support\AttachmentUpload::accept() }}"></label>
                <div class="ft-finance-uploading" wire:loading wire:target="invoiceSupportingDocument">Uploading document…</div>
                <x-jobs.finance.upload-preview :file="$invoiceSupportingDocument" remove-action="clearInvoiceSupportingDocument" title="Supporting document" />
                @error('invoiceSupportingDocument')<small class="error">{{ $message }}</small>@enderror
            </div>
            <aside class="ft-invoice-summary">
                <div><span>Subtotal</span><strong>{{ $currencyPrefix }}{{ number_format($itemsSubtotal, 2) }}</strong></div>
                @if($remoteAreaCharge > 0)
                    <div title="{{ trim((string) $invoiceRemoteAreaName) }}{{ $invoiceRemoteAreaPostalCode !== '' ? ' · '.$invoiceRemoteAreaPostalCode : '' }}"><span>Remote area charge</span><strong>{{ $currencyPrefix }}{{ number_format($remoteAreaCharge, 2) }}</strong></div>
                @endif
                <div><span>Tax {{ number_format($taxRate, $taxRate == floor($taxRate) ? 0 : 2) }}%</span><strong>{{ $currencyPrefix }}{{ number_format($taxAmount, 2) }}</strong></div>
                <div class="total amount"><span>Total</span><strong>{{ $currencyPrefix }}{{ number_format($total, 2) }}</strong></div>
            </aside>
        </div>

        <footer class="ft-finance-modal-foot">
            <span>Invoice number will be generated automatically.</span>
            <div><button type="button" class="secondary" wire:click="createInvoice(true)" wire:loading.attr="disabled" wire:target="createInvoice">Save as draft</button><button type="button" class="primary" wire:click="createInvoice(false)" wire:loading.attr="disabled" wire:target="createInvoice">Create</button></div>
        </footer>
    </section>
</div>
