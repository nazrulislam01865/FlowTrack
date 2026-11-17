@php
    $pricingTable = $currencyTable ?? $extraTable ?? [];
    $currencyCode = (string) ($pricingTable['currency'] ?? '');
    $currencyRows = collect($pricingTable['rows'] ?? [])->values();
    $currencyChargeMap = collect($pricingTable['charges'] ?? [])->keyBy('quantity');
    $primaryTable = (bool) ($isPrimary ?? false);
@endphp
<details class="ft-price-currency" @if($expanded ?? false) open @endif>
    <summary class="ft-price-currency-heading">
        <span class="ft-price-currency-identity">
            <strong>{{ $currencyCode }}</strong>
            <span class="ft-price-badge {{ $primaryTable ? 'is-primary' : 'is-muted' }}">{{ $primaryTable ? 'Primary table' : 'Additional currency' }}</span>
        </span>
        <span class="ft-price-currency-meta">
            {{ $currencyRows->count() ? $currencyRows->count().' quantity '.($currencyRows->count() === 1 ? 'tier' : 'tiers') : 'No prices set' }}
            <span class="ft-price-chevron" aria-hidden="true"></span>
        </span>
    </summary>
    <div class="ft-price-currency-body">
        @if($currencyRows->isNotEmpty())
            <div class="ft-product-price-preview-wrap ft-product-detail-price-wrap ft-price-matrix-scroll"
                 role="region" aria-label="{{ $supplierName ?? 'Supplier' }} {{ $currencyCode }} prices" tabindex="0">
                <table class="ft-product-price-preview ft-product-detail-matrix">
                    <thead><tr><th scope="col">Quantity</th>
                        @foreach($currencyRows as $tier)
                            <th scope="col">{{ number_format((int) $tier['quantity']) }}</th>
                        @endforeach
                    </tr></thead>
                    <tbody>
                        @foreach(['price' => 'Supplier price', 'remote_surcharge' => 'Remote surcharge', 'standard_shipment' => 'Standard shipment', 'urgent_shipment' => 'Urgent shipment'] as $field => $heading)
                            <tr><th scope="row">{{ $heading }}</th>
                                @foreach($currencyRows as $tier)
                                    @php
                                        $value = $field === 'price' ? ($tier['price'] ?? null)
                                            : data_get($currencyChargeMap->get((int) $tier['quantity']), $field);
                                    @endphp
                                    <td>{{ $value === null || $value === '' ? '—' : ((float) $value === 0.0 ? '0' : rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.')) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="ft-price-empty-state">No quantity-based prices configured for this currency.</p>
        @endif
        @if(($allowEdit ?? false) && !$primaryTable)
            <div class="ft-price-currency-actions">
                <button type="button" class="ft-product-page-btn is-secondary"
                    wire:click="openSupplierCurrencyTable({{ (int) $pricingTable['supplier_id'] }}, '{{ $currencyCode }}')">Edit {{ $currencyCode }} table</button>
            </div>
        @endif
    </div>
</details>
