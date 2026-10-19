@props(['invitation', 'token', 'products'])
@php
    $productCollection = collect($products);
    $rawRequirements = trim((string) ($invitation->supplier_details ?? ''));
    $requirements = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($rawRequirements), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $buyerNote = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) ($invitation->request_message ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
@endphp
<section class="ft-rfq-portal-card ft-rfq-product-to-quote" aria-labelledby="rfq-product-to-quote-title">
    <div class="ft-rfq-numbered-section-head">
        <span class="ft-rfq-numbered-section-head__number">2</span>
        <h2 id="rfq-product-to-quote-title">Product to quote</h2>
    </div>

    @foreach($productCollection as $product)
        <div class="ft-rfq-product-quote-grid">
            <div class="ft-rfq-product-quote-main">
                <x-rfq.public.product-thumb :product="$product" size="lg" />
                <div class="ft-rfq-product-quote-copy">
                    <strong>{{ $product['name'] }}</strong>
                    <div class="ft-rfq-product-quote-meta">
                        <span>Product code</span>
                        <b>{{ $product['code'] ?: '—' }}</b>
                        @if(filled($product['category']))<i aria-hidden="true">•</i><span>{{ $product['category'] }}</span>@endif
                    </div>
                </div>
                <div class="ft-rfq-requested-quantity">
                    <small>Requested quantity</small>
                    <strong>{{ number_format((float) $product['quantity'], fmod((float) $product['quantity'], 1.0) === 0.0 ? 0 : 2) }} {{ $product['unit'] }}</strong>
                </div>
            </div>

            <aside class="ft-rfq-buyer-requirements" id="rfq-buyer-requirements-{{ $product['item_id'] }}">
                <strong>Buyer requirements</strong>
                <p data-rfq-requirements-copy>{{ $requirements !== '' ? $requirements : 'No additional buyer requirements were provided for this quotation request.' }}</p>
                @if($buyerNote !== '')
                    <p class="ft-rfq-buyer-note"><strong>Buyer note:</strong> {{ $buyerNote }}</p>
                @endif
                <div class="ft-rfq-buyer-requirements__actions">
                    <a href="#rfq-buyer-requirements-{{ $product['item_id'] }}" data-rfq-toggle-requirements aria-expanded="false">View specifications <x-rfq.public.icon name="external" /></a>
                    @if(!empty($product['reference_documents']))
                        <span class="ft-rfq-reference-links">
                            @foreach($product['reference_documents'] as $referenceDocument)
                                <a href="{{ $referenceDocument['url'] }}" target="_blank" rel="noopener">{{ $loop->first ? 'Download reference files ('.count($product['reference_documents']).')' : $referenceDocument['label'] }} <x-rfq.public.icon name="download" /></a>
                            @endforeach
                        </span>
                    @endif
                </div>
            </aside>
        </div>
    @endforeach

    <div class="ft-rfq-product-scope-note">
        <x-rfq.public.icon name="info" />
        <span>Your quotation will apply only to {{ $productCollection->count() === 1 ? 'this product' : 'the products shown above' }}.</span>
    </div>
</section>
