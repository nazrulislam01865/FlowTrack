@props([
    'invitation', 'token', 'quote', 'products', 'documents', 'documentTypes', 'contact', 'rfqReference', 'currency',
    'productSubtotal', 'sampleCost', 'otherCosts', 'totalQuotedValue', 'clientName', 'readyToSubmit', 'locked' => false, 'submitted' => false, 'canRevise' => false,
])
@php
    $quoteItems = collect($quote?->items ?? [])->keyBy('inquiry_item_id');
    $documentCollection = collect($documents);
    $firstProduct = collect($products)->first();
    $validity = (int) ($quote?->validity_days ?? 30);
    $compliance = match($quote?->specification_compliance) { 'yes' => 'Yes, fully compliant', 'partial' => 'Partially compliant', 'no' => 'Not compliant', default => '—' };
@endphp
<div class="ft-rfq-portal-stack">
    <section class="ft-rfq-portal-card ft-rfq-review-intro">
        <h2>Review your quotation</h2>
        <p>Check all information before submitting. You can return to any section to make changes.</p>
        @unless($submitted)
            <div class="ft-rfq-warning-strip"><x-rfq.public.icon name="info" /> Submitting will send this quotation to {{ $clientName }}. You can revise it later from this secure link until the RFQ is closed.</div>
        @endunless
    </section>

    <section class="ft-rfq-portal-card ft-rfq-review-card">
        <div class="ft-rfq-review-card__head"><h2><span class="ft-rfq-review-check">✓</span> Supplier and RFQ details</h2>@unless($locked)<a href="{{ route('rfq.public.show', ['token' => $token, 'step' => 'details']) }}"><x-rfq.public.icon name="pencil" /> Edit</a>@endunless</div>
        <div class="ft-rfq-review-details-grid">
            <div><small>Supplier company</small><strong>{{ $invitation->supplier?->name ?: '—' }}</strong></div>
            <div><small>Contact person</small><strong>{{ $contact['name'] ?: '—' }}</strong></div>
            <div><small>Email</small><strong>{{ $contact['email'] ?: '—' }}</strong></div>
            <div><small>Phone</small><strong>{{ $contact['phone'] ?: '—' }}</strong></div>
            <div><small>Inquiry reference</small><strong>{{ $invitation->inquiry->inquiry_number }}</strong></div>
            <div><small>RFQ reference</small><strong>{{ $rfqReference }}</strong></div>
        </div>
    </section>

    <section class="ft-rfq-portal-card ft-rfq-review-card">
        <div class="ft-rfq-review-card__head"><h2><span class="ft-rfq-review-check">✓</span> Product and pricing</h2>@unless($locked)<a href="{{ route('rfq.public.show', ['token' => $token, 'step' => 'pricing']) }}"><x-rfq.public.icon name="pencil" /> Edit</a>@endunless</div>
        @foreach($products as $product)
            @php
                $item = $quoteItems->get($product['item_id']);
                $subtotal = (float) $product['quantity'] * (float) ($item?->unit_price ?? 0);
            @endphp
            <div class="ft-rfq-review-product-row">
                <div class="ft-rfq-review-product-info">
                    <x-rfq.public.product-thumb :product="$product" size="lg" />
                    <div><strong>{{ $product['name'] }}</strong><span>{{ $product['code'] ?: 'Product' }} &nbsp;·&nbsp; Requested {{ number_format((float) $product['quantity'], 0) }} {{ $product['unit'] }}</span></div>
                </div>
                <div class="ft-rfq-review-price-table">
                    <div><small>Quantity</small><strong>{{ number_format((float) $product['quantity'], 0) }}</strong></div>
                    <div><small>Currency</small><strong>{{ $currency }}</strong></div>
                    <div><small>Unit price</small><strong>{{ number_format((float) ($item?->unit_price ?? 0), 4) }}</strong></div>
                    <div><small>MOQ</small><strong>{{ $item?->moq !== null ? number_format((float) $item->moq, 0) : '—' }}</strong></div>
                    <div><small>Subtotal</small><strong>{{ $currency }} {{ number_format($subtotal, 2) }}</strong></div>
                </div>
            </div>
        @endforeach
        <div class="ft-rfq-review-costs-row">
            <div><small>Tooling / setup</small><strong>{{ $currency }} {{ number_format((float) ($quote?->tooling_cost ?? 0), 2) }}</strong></div>
            <div><small>Sample cost</small><strong>{{ $currency }} {{ number_format((float) ($quote?->sample_cost ?? 0), 2) }}</strong></div>
            <div><small>Discount</small><strong>{{ $currency }} {{ number_format((float) ($quote?->discount ?? 0), 2) }}</strong></div>
            <div><small>Tax</small><strong>{{ ucfirst((string) ($quote?->tax_status ?? 'excluded')) }}</strong></div>
            <div class="is-total"><small>Total quoted value</small><strong>{{ $currency }} {{ number_format($totalQuotedValue, 2) }}</strong></div>
        </div>
    </section>

    <section class="ft-rfq-portal-card ft-rfq-review-card">
        <div class="ft-rfq-review-card__head"><h2><span class="ft-rfq-review-check">✓</span> Production and delivery</h2>@unless($locked)<a href="{{ route('rfq.public.show', ['token' => $token, 'step' => 'pricing']) }}"><x-rfq.public.icon name="pencil" /> Edit</a>@endunless</div>
        <div class="ft-rfq-review-delivery-grid">
            <div><small>Production lead time</small><strong>{{ $quote?->lead_time_days !== null ? $quote->lead_time_days.' days' : '—' }}</strong></div>
            <div><small>Sample lead time</small><strong>{{ $quote?->sample_lead_time_days !== null ? $quote->sample_lead_time_days.' days' : '—' }}</strong></div>
            <div><small>Incoterm</small><strong>{{ $quote?->incoterm ?: '—' }}</strong></div>
            <div><small>Shipping port</small><strong>{{ $quote?->shipping_port ?: '—' }}</strong></div>
            <div><small>Estimated delivery</small><strong>{{ $quote?->estimated_delivery_date?->format('M j, Y') ?: '—' }}</strong></div>
            <div><small>Quote validity</small><strong>{{ $validity }} days</strong></div>
            <div><small>Specification compliance</small><strong>{{ $compliance }}</strong></div>
        </div>
        @if(filled($quote?->notes))<p class="ft-rfq-review-production-note">{{ $quote->notes }}</p>@endif
    </section>

    <section class="ft-rfq-portal-card ft-rfq-review-card">
        <div class="ft-rfq-review-card__head"><h2><span class="ft-rfq-review-check">✓</span> Documents ({{ $documentCollection->count() }})</h2>@unless($locked)<a href="{{ route('rfq.public.show', ['token' => $token, 'step' => 'documents']) }}"><x-rfq.public.icon name="pencil" /> Edit</a>@endunless</div>
        <div class="ft-rfq-review-docs">
            @forelse($documentCollection as $document)
                @php
                    $extension = strtolower(pathinfo((string) $document->name, PATHINFO_EXTENSION));
                    $size = (int) $document->size;
                    $sizeLabel = $size >= 1048576 ? number_format($size / 1048576, 1).' MB' : number_format(max(1, (int) ceil($size / 1024)), 0).' KB';
                @endphp
                <div class="ft-rfq-review-doc-row">
                    <span class="ft-rfq-file-icon is-{{ $extension }}">{{ strtoupper(substr($extension ?: 'FILE', 0, 4)) }}</span>
                    <strong>{{ $document->name }}</strong>
                    <small>{{ $documentTypes[$document->document_type] ?? 'Document' }} &nbsp;·&nbsp; {{ $sizeLabel }}</small>
                    <span class="ft-rfq-ready-status">✓ Ready</span>
                    <a href="{{ route('rfq.public.documents.preview', ['token' => $token, 'document' => $document->id]) }}" target="_blank" rel="noopener">Preview</a>
                </div>
            @empty
                <div class="ft-rfq-empty-docs">No documents attached.</div>
            @endforelse
        </div>
    </section>

    <form method="post" action="{{ route('rfq.public.respond', ['token' => $token]) }}" id="rfq-review-form">
        @csrf
        <section class="ft-rfq-final-declaration {{ $submitted ? 'is-submitted' : '' }}">
            <h2>Final declaration</h2>
            <label><input type="checkbox" name="declaration_accuracy" value="1" @checked($submitted) @disabled($submitted)><span>I confirm that the information provided is accurate and that this quotation is valid for {{ $validity }} days.</span></label>
            <label><input type="checkbox" name="declaration_authority" value="1" @checked($submitted) @disabled($submitted)><span>I am authorized to submit this quotation on behalf of <strong>{{ $invitation->supplier?->name }}</strong>.</span></label>
            <p>Submitted by <strong>{{ $quote?->submitted_by_name ?: ($contact['name'] ?? '—') }}</strong> &nbsp;·&nbsp; {{ $quote?->submitted_by_email ?: ($contact['email'] ?? '—') }}</p>
        </section>

        @if(!$locked)
            <div class="ft-rfq-portal-bottom-actions">
                <a class="ft-rfq-btn is-secondary" href="{{ route('rfq.public.show', ['token' => $token, 'step' => 'documents']) }}"><x-rfq.public.icon name="arrow-left" /> Back to documents</a>
                <div>
                    <button type="submit" class="ft-rfq-btn is-secondary" name="action" value="save_review"><x-rfq.public.icon name="save" /> Save draft</button>
                    <button type="submit" class="ft-rfq-btn is-primary" name="action" value="submit" @disabled(!$readyToSubmit)>Submit quotation <x-rfq.public.icon name="chevron-right" /></button>
                </div>
            </div>
            <p class="ft-rfq-confirmation-note"><x-rfq.public.icon name="lock" /> A confirmation email will be sent after submission.</p>
        @elseif($submitted)
            <div class="ft-rfq-submitted-banner">
                <span>✓ Quotation submitted successfully.</span>
                @if($canRevise)
                    <button type="submit" class="ft-rfq-btn is-secondary" form="rfq-revise-form">
                        <x-rfq.public.icon name="pencil" /> Revise quotation
                    </button>
                @else
                    <small>This quotation is locked because the RFQ is closed or the revision window has ended.</small>
                @endif
            </div>
        @endif
    </form>

    @if($submitted && $canRevise)
        <form method="post" action="{{ route('rfq.public.respond', ['token' => $token]) }}" id="rfq-revise-form" class="ft-rfq-hidden-form">
            @csrf
            <input type="hidden" name="action" value="revise">
        </form>
    @endif
</div>
