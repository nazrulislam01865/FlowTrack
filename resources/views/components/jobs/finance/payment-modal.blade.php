@props([
    'job',
    'paymentInvoiceId' => null,
    'paymentAmount' => '',
    'paymentReceipt' => null,
    'paymentMethods' => collect(),
    'receivedAccounts' => collect(),
])

@php
    $selectedPaymentInvoice = $job->invoices->firstWhere('id', (int) $paymentInvoiceId);
    $invoiceBalance = $selectedPaymentInvoice ? (float) $selectedPaymentInvoice->balanceAmount() : 0.0;
    $amountToApply = is_numeric($paymentAmount) ? max(0, (float) $paymentAmount) : 0.0;
    $amountToApply = min($amountToApply, $invoiceBalance);
    $afterPayment = max(0, $invoiceBalance - $amountToApply);
    $paymentCurrency = $selectedPaymentInvoice?->currency ?: 'USD';
    $currencySymbol = match ($paymentCurrency) {
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'CNY', 'RMB' => '¥',
        default => $paymentCurrency.' ',
    };
    $willFullyPay = $selectedPaymentInvoice && $invoiceBalance > 0 && $afterPayment <= 0.009;
@endphp

<div class="ft-finance-modal-backdrop" wire:key="record-payment-modal" wire:click.self="closeRecordPayment">
    <section class="ft-finance-modal ft-record-payment-modal" role="dialog" aria-modal="true" aria-labelledby="recordPaymentTitle">
        <header class="ft-finance-modal-head ft-record-payment-head">
            <div>
                <h2 id="recordPaymentTitle">Record payment</h2>
                <p>Record money received and apply it to an invoice.</p>
            </div>
            <button type="button" wire:click="closeRecordPayment" aria-label="Close">×</button>
        </header>

        @error('paymentForm')
            <div class="ft-finance-form-alert">{{ $message }}</div>
        @enderror

        <div class="ft-record-payment-body">
            <div class="ft-record-payment-grid">
                <label>
                    <span>Payment amount <b>*</b></span>
                    <div class="ft-payment-money-input">
                        <span>{{ $currencySymbol }}</span>
                        <input type="number" step="0.01" min="0.01" wire:model.live.debounce.250ms="paymentAmount" inputmode="decimal">
                    </div>
                    @error('paymentAmount')<small class="error">{{ $message }}</small>@enderror
                </label>

                <label>
                    <span>Payment date <b>*</b></span>
                    <input type="date" wire:model="paymentDate">
                    @error('paymentDate')<small class="error">{{ $message }}</small>@enderror
                </label>

                <label>
                    <span>Payment method <b>*</b></span>
                    <select wire:model="paymentMethod">
                        <option value="">Select payment method</option>
                        @foreach($paymentMethods as $option)
                            <option value="{{ $option->name }}">{{ $option->name }}</option>
                        @endforeach
                    </select>
                    @error('paymentMethod')<small class="error">{{ $message }}</small>@enderror
                </label>

                <label>
                    <span>Reference number</span>
                    <input type="text" wire:model="paymentReference" placeholder="TRX-240926-1088">
                    @error('paymentReference')<small class="error">{{ $message }}</small>@enderror
                </label>

                <label>
                    <span>Received in account</span>
                    <select wire:model="paymentReceivedAccount">
                        <option value="">Select received account</option>
                        @foreach($receivedAccounts as $option)
                            <option value="{{ $option->name }}">{{ $option->name }}</option>
                        @endforeach
                    </select>
                    @error('paymentReceivedAccount')<small class="error">{{ $message }}</small>@enderror
                </label>

                <label>
                    <span>Apply payment to <b>*</b></span>
                    <select wire:model.live="paymentInvoiceId">
                        <option value="">Select invoice</option>
                        @foreach($job->invoices as $invoice)
                            @if(!in_array($invoice->status, ['draft','cancelled','paid'], true) && $invoice->balanceAmount() > 0)
                                <option value="{{ $invoice->id }}">{{ $invoice->invoice_number }} · {{ $invoice->type }}</option>
                            @endif
                        @endforeach
                    </select>
                    @error('paymentInvoiceId')<small class="error">{{ $message }}</small>@enderror
                </label>
            </div>

            <section class="ft-payment-allocation" aria-label="Payment allocation">
                <h3>Allocation</h3>
                <div class="ft-payment-allocation-panel">
                    <div class="ft-payment-allocation-stat">
                        <span>Invoice balance</span>
                        <strong>{{ $currencySymbol }}{{ number_format($invoiceBalance, 2) }}</strong>
                    </div>
                    <div class="ft-payment-allocation-stat">
                        <span>Amount to apply</span>
                        <strong>{{ $currencySymbol }}{{ number_format($amountToApply, 2) }}</strong>
                    </div>
                    <div class="ft-payment-allocation-stat">
                        <span>After payment</span>
                        <strong>{{ $currencySymbol }}{{ number_format($afterPayment, 2) }} outstanding</strong>
                    </div>
                    <div class="ft-payment-allocation-result {{ $willFullyPay ? 'is-paid' : '' }}">
                        <span class="ft-payment-result-icon">{{ $willFullyPay ? '✓' : '•' }}</span>
                        <span>{{ $willFullyPay ? 'Invoice will be fully paid' : 'Invoice will remain partially paid' }}</span>
                    </div>
                </div>
            </section>

            <label class="ft-payment-upload">
                <input type="file" wire:model="paymentReceipt" accept="{{ \App\Support\AttachmentUpload::accept() }}">
                <span class="ft-payment-paperclip" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M21.4 11.6 12 21a6 6 0 0 1-8.5-8.5l10-10a4 4 0 0 1 5.7 5.7L9.7 17.7a2 2 0 1 1-2.8-2.8l8.6-8.6"/></svg>
                </span>
                <span class="ft-payment-upload-copy">Attach receipt or bank advice</span>
                <span class="ft-payment-browse">Browse</span>
            </label>
            <div class="ft-finance-uploading" wire:loading wire:target="paymentReceipt">Uploading receipt…</div>
            <x-jobs.finance.upload-preview :file="$paymentReceipt" remove-action="clearPaymentReceipt" title="Receipt / bank advice" />
            @error('paymentReceipt')<small class="error ft-payment-upload-error">{{ $message }}</small>@enderror

            <label class="ft-payment-note">
                <span>Note</span>
                <textarea wire:model="paymentNotes" placeholder="Client paid the remaining final invoice balance."></textarea>
                @error('paymentNotes')<small class="error">{{ $message }}</small>@enderror
            </label>

            <label class="ft-payment-checkbox">
                <input type="checkbox" wire:model="paymentMarkInvoicePaid">
                <span>Mark invoice as paid when its balance reaches zero</span>
            </label>
        </div>

        <footer class="ft-finance-modal-foot ft-record-payment-foot">
            <span>Payment ID will be generated automatically.</span>
            <div>
                <button type="button" class="secondary" wire:click="closeRecordPayment">Cancel</button>
                <button type="button" class="primary" wire:click="recordPayment" wire:loading.attr="disabled" wire:target="recordPayment,paymentReceipt">Record payment</button>
            </div>
        </footer>
    </section>
</div>
