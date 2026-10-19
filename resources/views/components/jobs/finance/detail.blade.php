@props([
    'job','summary'=>[],'redoContext'=>[],'contacts'=>collect(),'users'=>collect(),
    'invoiceTypes'=>collect(),'currencies'=>collect(),'paymentTerms'=>collect(),'paymentMethods'=>collect(),'receivedAccounts'=>collect(),
    'canCreate'=>false,'canEdit'=>false,
    'showCreateInvoiceModal'=>false,'invoiceType'=>'Final invoice','invoiceCurrency'=>'USD','invoiceIssueDate'=>'','invoicePaymentTerms'=>'Net 15 days','invoiceDueDate'=>'','invoiceBillingContactId'=>null,'invoiceLineItems'=>[],'invoicePurchaseOrderReference'=>'','invoiceNotes'=>'','invoiceTaxRate'=>'0','invoiceRemoteAreaCharge'=>0,'invoiceRemoteAreaName'=>'','invoiceRemoteAreaPostalCode'=>'','invoiceSupportingDocument'=>null,'invoiceEmailAfterCreation'=>false,
    'showRecordPaymentModal'=>false,'paymentInvoiceId'=>null,'paymentDate'=>'','paymentMethod'=>'Bank transfer','paymentAmount'=>'','paymentReference'=>'','paymentNotes'=>'','paymentReceipt'=>null,
    'showCollectionUpdateModal'=>false,'collectionOwnerId'=>null,'collectionFollowUpDate'=>'','collectionNextFollowUpDate'=>'','collectionNote'=>'',
])
@php
    $currency = strtoupper((string)($job->currency ?: 'USD'));
    $money = fn($amount, $code = null) => (($code ?: $currency) === 'USD' ? '$' : ($code ?: $currency).' ').number_format((float)$amount, 2);
    $collection = $job->collection;
    $overdueDays = (int)($summary['overdue_days'] ?? 0);
@endphp
<section class="ft-order-finance">
    <div class="ft-finance-title-row">
        <div><h2>Invoices &amp; Payments</h2><p>Track billing, collections and outstanding balances for this order.</p></div>
    </div>

    <div class="ft-finance-metrics">
        <x-jobs.finance.metric-card label="Order Value" :value="$money($summary['order_value'] ?? 0)" icon="document" tone="blue" />
        <x-jobs.finance.metric-card label="Total Invoiced" :value="$money($summary['total_invoiced'] ?? 0)" icon="money" tone="blue" />
        <x-jobs.finance.metric-card label="Total Collected" :value="$money($summary['total_collected'] ?? 0)" :subline="number_format((float)($summary['collection_pct'] ?? 0),1).'% collected'" icon="collect" tone="green" />
        <x-jobs.finance.metric-card label="Outstanding" :value="$money($summary['outstanding'] ?? 0)" icon="outstanding" tone="blue" />
        <x-jobs.finance.metric-card label="Overdue" :value="$money($summary['overdue'] ?? 0)" icon="warning" tone="red" :danger="(float)($summary['overdue'] ?? 0) > 0" />
    </div>

    <x-jobs.order-detail.redo-finance :job="$job" :context="$redoContext" />

    @if((float)($summary['overdue'] ?? 0) > 0)
        <div class="ft-finance-overdue">
            <span class="ft-overdue-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 2.7 20h18.6z"></path><path d="M12 8v5.5M12 17h.01"></path></svg></span>
            <div><strong>Payment overdue</strong><p>{{ $money($summary['overdue']) }} has been overdue{{ $overdueDays ? ' for '.$overdueDays.' day'.($overdueDays===1?'':'s') : '' }}.</p></div>
            <div class="ft-overdue-meta"><span>Collection owner: <b>{{ $collection?->owner?->name ?: 'Unassigned' }}</b></span><i>•</i><span>Next follow-up: <b>{{ $collection?->next_follow_up_at ? \App\Support\UserLocalTime::format($collection->next_follow_up_at, 'M j, Y') : 'Not set' }}</b></span></div>
            <div class="ft-overdue-actions">
                @if($canEdit)<button type="button" class="reminder" wire:click="sendPaymentReminder" wire:loading.attr="disabled" wire:target="sendPaymentReminder">Send reminder</button>@endif
                @if($canEdit)<button type="button" class="update" wire:click="openCollectionUpdate">Add collection update</button>@endif
            </div>
        </div>
    @elseif($canEdit && $job->invoices->isNotEmpty())
        <div class="ft-finance-healthy"><span>✓</span><div><strong>No overdue balance</strong><p>All issued invoices are currently within terms or fully paid.</p></div><button type="button" wire:click="openCollectionUpdate">Add collection update</button></div>
    @endif
    @error('collectionForm')<div class="ft-finance-form-alert ft-finance-page-alert">{{ $message }}</div>@enderror

    <section class="ft-finance-table-card">
        <h3>Invoices</h3>
        <div class="ft-finance-table-wrap">
            <table class="ft-finance-table">
                <thead><tr><th>Invoice</th><th>Type</th><th>Issue date</th><th>Due date</th><th>Amount</th><th>Collected</th><th>Balance</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                @forelse($job->invoices as $invoice)
                    @php($collected = $invoice->collectedAmount())
                    @php($balance = $invoice->balanceAmount())
                    <tr>
                        <td><strong class="ft-finance-link">{{ $invoice->invoice_number }}</strong></td>
                        <td>{{ str_replace(' invoice','',$invoice->type) }}</td>
                        <td>{{ \App\Support\UserLocalTime::format($invoice->issue_date, 'M j, Y') }}</td>
                        <td>{{ \App\Support\UserLocalTime::format($invoice->due_date, 'M j, Y') }}</td>
                        <td>{{ $money($invoice->total, $invoice->currency) }}</td>
                        <td>{{ $money($collected, $invoice->currency) }}</td>
                        <td>{{ $money($balance, $invoice->currency) }}</td>
                        <td><x-jobs.finance.status :status="$invoice->status" /></td>
                        <td><button type="button" class="ft-finance-kebab" aria-label="Invoice actions">•••</button></td>
                    </tr>
                    @if($invoice->pdf_path)
                        <x-jobs.finance.attachment-row
                            :colspan="9"
                            :name="$invoice->pdf_name ?: $invoice->invoice_number.'.pdf'"
                            :meta="'Generated invoice · '.($invoice->creator?->name ?: 'System').' · '.($invoice->pdf_generated_at ? \App\Support\UserLocalTime::format($invoice->pdf_generated_at, 'M j, Y, g:i A') : \App\Support\UserLocalTime::format($invoice->created_at, 'M j, Y, g:i A'))"
                            :open-url="route('invoices.pdf.open', $invoice)"
                            :download-url="route('invoices.pdf.download', $invoice)"
                        />
                    @endif
                    @if($invoice->supporting_document_path)
                        <x-jobs.finance.attachment-row
                            :colspan="9"
                            :name="$invoice->supporting_document_name ?: basename($invoice->supporting_document_path)"
                            :meta="'Invoice supporting document · '.($invoice->creator?->name ?: 'System').' · '.\App\Support\UserLocalTime::format($invoice->created_at, 'M j, Y, g:i A')"
                            :open-url="route('invoices.attachment.open', $invoice)"
                            :download-url="route('invoices.attachment.download', $invoice)"
                        />
                    @endif
                @empty
                    <tr><td colspan="9"><div class="ft-finance-empty"><strong>No invoices yet</strong><span>Create the first invoice for this order when billing is ready.</span>@if($canCreate)<button type="button" wire:click="openCreateInvoice">Create invoice</button>@endif</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="ft-finance-bottom-grid">
        <section class="ft-finance-table-card ft-payment-history">
            <h3>Payment history</h3>
            <div class="ft-finance-table-wrap">
                <table class="ft-finance-table">
                    <thead><tr><th>Payment</th><th>Date</th><th>Method</th><th>Amount</th><th>Applied to</th><th>Recorded by</th></tr></thead>
                    <tbody>
                    @forelse($job->payments as $payment)
                        <tr><td><strong class="ft-finance-link">{{ $payment->payment_number }}</strong></td><td>{{ \App\Support\UserLocalTime::format($payment->payment_date, 'M j, Y') }}</td><td>{{ $payment->method }}</td><td>{{ $money($payment->amount, $payment->invoice?->currency ?: $currency) }}</td><td><strong class="ft-finance-link">{{ $payment->invoice?->invoice_number ?: '—' }}</strong></td><td>{{ $payment->recorder?->name ?: 'System' }}</td></tr>
                        @if($payment->receipt_path)
                            <x-jobs.finance.attachment-row
                                :colspan="6"
                                :name="$payment->receipt_name ?: basename($payment->receipt_path)"
                                :meta="'Payment receipt / bank advice · '.($payment->recorder?->name ?: 'System').' · '.\App\Support\UserLocalTime::format($payment->created_at, 'M j, Y, g:i A')"
                                :open-url="route('payments.receipt.open', $payment)"
                                :download-url="route('payments.receipt.download', $payment)"
                            />
                        @endif
                    @empty
                        <tr><td colspan="6"><div class="ft-finance-empty compact"><span>No payments have been recorded for this order.</span></div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        <section class="ft-finance-collection-card">
            <h3>Collection details</h3>
            <dl>
                <div><dt>Collection owner</dt><dd>{{ $collection?->owner?->name ?: 'Unassigned' }} @if($canEdit)<button type="button" wire:click="openCollectionUpdate" aria-label="Edit collection owner">✎</button>@endif</dd></div>
                <div><dt>Last follow-up</dt><dd>{{ $collection?->last_follow_up_at ? \App\Support\UserLocalTime::format($collection->last_follow_up_at, 'M j, Y') : '—' }}</dd></div>
                <div><dt>Next follow-up</dt><dd>{{ $collection?->next_follow_up_at ? \App\Support\UserLocalTime::format($collection->next_follow_up_at, 'M j, Y') : '—' }}</dd></div>
                <div><dt>Latest note</dt><dd>{{ $collection?->latest_note ?: 'No collection note yet.' }}</dd></div>
            </dl>
            <div class="ft-collection-footer">
                @if($collection?->updates?->isNotEmpty())
                    <details><summary>View collection history</summary><div class="ft-collection-history">@foreach($collection->updates->take(8) as $update)<div><b>{{ $update->actor?->name ?: 'System' }}</b><span>{{ $update->note }}</span><small>{{ $update->created_at ? \App\Support\UserLocalTime::format($update->created_at, 'M j, Y · g:i A') : '' }}</small></div>@endforeach</div></details>
                @else<span></span>@endif
                @if($canEdit)<button type="button" wire:click="openCollectionUpdate">Add update</button>@endif
            </div>
        </section>
    </div>

    @if($showCreateInvoiceModal)
        <x-jobs.finance.create-invoice-modal
            :job="$job" :contacts="$contacts" :summary="$summary"
            :invoice-types="$invoiceTypes" :currencies="$currencies" :payment-terms="$paymentTerms"
            :invoice-line-items="$invoiceLineItems" :invoice-tax-rate="$invoiceTaxRate" :invoice-currency="$invoiceCurrency" :invoice-type="$invoiceType"
            :invoice-remote-area-charge="$invoiceRemoteAreaCharge" :invoice-remote-area-name="$invoiceRemoteAreaName" :invoice-remote-area-postal-code="$invoiceRemoteAreaPostalCode"
            :invoice-supporting-document="$invoiceSupportingDocument"
        />
    @endif
    @if($showRecordPaymentModal)
        <x-jobs.finance.payment-modal
            :job="$job"
            :payment-methods="$paymentMethods"
            :received-accounts="$receivedAccounts"
            :payment-invoice-id="$paymentInvoiceId"
            :payment-amount="$paymentAmount"
            :payment-receipt="$paymentReceipt"
        />
    @endif
    @if($showCollectionUpdateModal)
        <x-jobs.finance.collection-update-modal :users="$users" />
    @endif
</section>
