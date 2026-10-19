@props([
    'invitation', 'token', 'step', 'quote', 'firstProduct', 'currency', 'totalQuantity', 'productSubtotal',
    'sampleCost', 'otherCosts', 'totalQuotedValue', 'detailsComplete', 'pricingComplete', 'documents',
    'documentsComplete' => false, 'readyToSubmit', 'locked' => false, 'submitted' => false, 'canRevise' => false,
])
@php
    $formId = match($step) {
        'details' => 'rfq-details-form',
        'pricing' => 'rfq-pricing-form',
        'documents' => 'rfq-documents-form',
        default => 'rfq-review-form',
    };
    $saveAction = match($step) {
        'details' => 'save_details',
        'pricing' => 'save_pricing',
        'documents' => 'save_documents',
        default => 'save_review',
    };
    $primaryAction = match($step) {
        'details' => 'continue_pricing',
        'pricing' => 'continue_documents',
        'documents' => 'continue_review',
        default => 'submit',
    };
    $primaryLabel = match($step) {
        'details' => 'Continue to pricing',
        'pricing' => 'Review & submit',
        'documents' => 'Continue to review',
        default => 'Submit quotation',
    };
    $docs = collect($documents);
@endphp
<section class="ft-rfq-summary-card">
    <h2>Quotation summary</h2>
    <div class="ft-rfq-summary-product">
        <x-rfq.public.product-thumb :product="$firstProduct ?? []" size="lg" />
        <strong>{{ $firstProduct['name'] ?? 'Requested product' }}</strong>
    </div>
    <dl class="ft-rfq-summary-meta">
        <div><dt>Supplier</dt><dd>{{ $invitation->supplier?->name ?: '—' }}</dd></div>
        <div><dt>Requested quantity</dt><dd>{{ number_format((float) $totalQuantity, 0) }} units</dd></div>
        <div><dt>Quotation due</dt><dd>{{ $invitation->due_at?->format('M j, Y') ?? 'No due date' }}</dd></div>
    </dl>
    <div class="ft-rfq-summary-divider"></div>
    <dl class="ft-rfq-summary-costs">
        <div><dt>Product subtotal</dt><dd data-rfq-summary-product-subtotal>{{ $currency }} {{ number_format((float) $productSubtotal, 2) }}</dd></div>
        <div><dt>Sample cost</dt><dd data-rfq-summary-sample-cost>{{ $currency }} {{ number_format((float) $sampleCost, 2) }}</dd></div>
        <div><dt>Other costs</dt><dd data-rfq-summary-other-costs>{{ $currency }} {{ number_format((float) $otherCosts, 2) }}</dd></div>
    </dl>
    <div class="ft-rfq-summary-divider"></div>
    <div class="ft-rfq-summary-total"><span>Total quoted value</span><strong>{{ $currency }} {{ number_format((float) $totalQuotedValue, 2) }}</strong></div>

    <div class="ft-rfq-summary-progress">
        <div class="{{ $detailsComplete ? 'is-complete' : '' }}"><span>{{ $detailsComplete ? '✓' : '○' }}</span> Product reviewed</div>
        <div class="{{ $pricingComplete ? 'is-complete' : '' }}"><span>{{ $pricingComplete ? '✓' : '○' }}</span> Pricing completed</div>
        @if($step === 'pricing')
            <div class="{{ $docs->isNotEmpty() ? 'is-complete' : '' }}" data-rfq-summary-document data-existing-documents="{{ $docs->count() }}"><span>{{ $docs->isNotEmpty() ? '✓' : '○' }}</span> Document attached</div>
            <div class="is-pending" data-rfq-summary-confirmation><span>○</span> Confirmation required</div>
        @else
            <div class="{{ $documentsComplete ? 'is-complete' : '' }}"><span>{{ $documentsComplete ? '✓' : '○' }}</span> {{ $docs->count() }} {{ \Illuminate\Support\Str::plural('document', $docs->count()) }} attached</div>
            <div class="{{ ($step === 'review' && $readyToSubmit) || $submitted ? 'is-complete' : 'is-pending' }}"><span>{{ (($step === 'review' && $readyToSubmit) || $submitted) ? '✓' : '○' }}</span> {{ $submitted ? 'Quotation submitted' : (($step === 'review' && $readyToSubmit) ? 'Ready to submit' : 'Review not completed') }}</div>
        @endif
    </div>

    @unless($locked)
        <div class="ft-rfq-summary-actions">
            <button type="submit" class="ft-rfq-btn is-secondary is-full" form="{{ $formId }}" name="action" value="{{ $saveAction }}"><x-rfq.public.icon name="save" /> Save draft</button>
            <button type="submit" class="ft-rfq-btn is-primary is-full" form="{{ $formId }}" name="action" value="{{ $primaryAction }}" @disabled($step === 'review' && !$readyToSubmit)>{{ $primaryLabel }} <x-rfq.public.icon name="chevron-right" /></button>
            <form method="post" action="{{ route('rfq.public.respond', ['token' => $token]) }}">@csrf<button type="submit" name="action" value="decline" class="ft-rfq-decline-link" data-rfq-decline>Decline to quote</button></form>
        </div>
        @if($step === 'review')<div class="ft-rfq-secure-submission"><x-rfq.public.icon name="lock" /> Secure submission</div>@endif
    @elseif($submitted)
        <div class="ft-rfq-summary-submitted">✓ Submitted</div>
        @if($canRevise)
            <div class="ft-rfq-summary-actions is-submitted-actions">
                <form method="post" action="{{ route('rfq.public.respond', ['token' => $token]) }}">
                    @csrf
                    <button type="submit" name="action" value="revise" class="ft-rfq-btn is-secondary is-full"><x-rfq.public.icon name="pencil" /> Revise quotation</button>
                </form>
            </div>
        @endif
    @endif
</section>
