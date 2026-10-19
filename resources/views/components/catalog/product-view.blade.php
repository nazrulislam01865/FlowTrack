@props(['product', 'suppliers' => collect(), 'supplierPricingTables' => collect(), 'canEdit' => false, 'canDelete' => false, 'displayTimezone' => 'UTC', 'detailSectionsReady' => []])
@php
    $pricingReady = (bool) ($detailSectionsReady['pricing'] ?? false);
    $optionsReady = (bool) ($detailSectionsReady['options'] ?? false);
    $documentsReady = (bool) ($detailSectionsReady['documents'] ?? false);
    $created = $product->created_at?->copy()->timezone($displayTimezone);
    $updated = $product->updated_at?->copy()->timezone($displayTimezone);
    $classification = collect([$product->productMainCategory(), $product->parent?->name, trim((string) data_get($product->metadata, 'sub_category'))])->filter()->values();

    // These helpers can parse legacy metadata and build large arrays. Keep that
    // CPU/HTML work behind viewport boundaries instead of doing it above fold.
    $clientPriceTables = $pricingReady ? $product->clientPrices : collect();
    $remoteSurchargeBreakpoints = $pricingReady ? collect($product->productRemoteSurchargeBreakpoints())->keyBy('quantity') : collect();
    $productOptions = $optionsReady ? collect($product->productOptions()) : collect();
    $shipmentUrgencyOptions = $optionsReady ? collect($product->productShipmentUrgencyOptions()) : collect();
    $documents = $documentsReady ? collect($product->productDocuments()) : collect();
    $certificate = $documentsReady ? $documents->firstWhere('kind', 'certificate') : null;
    $template = $documentsReady ? $documents->firstWhere('kind', 'template') : null;
@endphp
<div class="ft-product-page ft-product-view-page">
    <div class="ft-product-page-breadcrumb"><button type="button" wire:click="closeProductView">Products</button><span>/</span><strong>{{ $product->productDisplayCode() }}</strong></div>
    <header class="ft-product-detail-header">
        <div>
            <h1>{{ $product->name }}</h1>
            <div class="ft-product-detail-meta">
                <x-catalog.status :active="$product->status === 'active'" />
                <span>Product code {{ $product->productDisplayCode() }}</span>
                <span>Updated {{ $updated?->format('M j, Y') ?? '—' }}</span>
                <span>Created by {{ $product->creator?->name ?? '—' }}</span>
            </div>
        </div>
        <div class="ft-product-detail-actions">
            <button type="button" class="ft-product-page-btn is-secondary" wire:click="closeProductView">Back to products</button>
            @if($canEdit)<button type="button" class="ft-product-page-btn is-primary" wire:click="editProduct({{ $product->id }})">Edit product</button>@endif
            <x-catalog.action-menu :product-id="$product->id" :is-active="$product->status === 'active'" :can-edit="$canEdit" :can-delete="$canDelete" />
        </div>
    </header>

    <x-catalog.product-section title="Product details">
        <div class="ft-product-detail-grid">
            <dl class="ft-product-detail-list">
                <div><dt>Product code</dt><dd>{{ $product->productDisplayCode() }} <button type="button" class="ft-copy-btn" x-data x-on:click="navigator.clipboard?.writeText('{{ $product->productDisplayCode() }}')" aria-label="Copy product code">⧉</button></dd></div>
                <div><dt>Reference product code</dt><dd>{{ $product->productReferenceCode() ?: '—' }}</dd></div>
                <div><dt>Product name</dt><dd>{{ $product->name }}</dd></div>
                <div><dt>Product size</dt><dd class="ft-product-detail-size">{{ $product->productSize() ?: '—' }}</dd></div>
                <div class="ft-product-detail-supplier-row">
                    <dt>Tagged suppliers</dt>
                    <dd class="ft-product-detail-supplier-capsules">
                        @forelse($suppliers as $supplier)
                            <span class="ft-product-detail-supplier-capsule">
                                {{ $supplier->supplierShortCode() }}
                                @if((int) $supplier->id === (int) $product->productSupplierId())<small>Default</small>@endif
                            </span>
                        @empty
                            <span class="ft-product-detail-supplier-empty">No suppliers tagged</span>
                        @endforelse
                    </dd>
                </div>
            </dl>
            <div class="ft-product-detail-image-panel">
                <div class="ft-product-detail-image">
                    @if($product->productImageUrl())<img src="{{ $product->productImageUrl() }}" alt="{{ $product->name }}">@else<span class="ft-product-image-placeholder">No image</span>@endif
                </div>
                @if($product->productImageUrl())<a href="{{ $product->productImageUrl() }}" target="_blank" rel="noopener">View full image</a>@endif
            </div>
        </div>
    </x-catalog.product-section>

    <x-catalog.product-section title="Classification & availability">
        <div class="ft-product-classification-row">
            @foreach($classification as $index => $label)
                <div class="ft-product-classification-step"><small>{{ ['Main category','Product category','Subcategory'][$index] ?? 'Category' }}</small><x-catalog.product-chip :label="$label" /></div>
                @if(!$loop->last)<span class="ft-product-classification-arrow">›</span>@endif
            @endforeach
        </div>
        <div class="ft-product-availability-detail">
            <div><strong>Client availability</strong><span>{{ $product->hasSpecificProductAvailability() ? 'Selected clients' : 'All clients' }}</span><small>{{ $product->hasSpecificProductAvailability() ? 'Only these clients can find and use this product.' : 'All active clients can find and use this product.' }}</small></div>
            <div class="ft-product-client-badges">
                @foreach($product->productAvailabilityLabels() as $label)<span>{{ $label }}</span>@endforeach
                @if($product->hasSpecificProductAvailability())<em>{{ count($product->productAvailabilityLabels()) }} clients</em>@endif
            </div>
        </div>
    </x-catalog.product-section>

    @if($pricingReady)
    @if(app(\App\Services\Orders\SupplierCostVisibility::class)->allowed() && $supplierPricingTables->isNotEmpty())
        <x-catalog.product-section title="Supplier-based pricing">
            @foreach($supplierPricingTables as $supplierTable)
                <div class="ft-product-price-preview-wrap ft-product-detail-price-wrap" style="margin-bottom:14px">
                    <strong>{{ $supplierTable['name'] }}</strong>
                    @if(count($supplierTable['rows']))
                        <table class="ft-product-price-preview">
                            <thead><tr><th>Quantity</th>
                                @foreach($supplierTable['rows'] as $priceRow)
                                    <th>{{ number_format((int) $priceRow['quantity']) }}</th>
                                @endforeach
                            </tr></thead><tbody><tr><th>Supplier price</th>
                                @foreach($supplierTable['rows'] as $priceRow)
                                    <td>{{ (float) $priceRow['price'] === 0.0 ? '0' : rtrim(rtrim(number_format((float) $priceRow['price'], 6, '.', ''), '0'), '.') }}</td>
                                @endforeach
                            </tr></tbody>
                        </table>
                    @else
                        <small>No supplier prices entered.</small>
                    @endif
                </div>
            @endforeach
        </x-catalog.product-section>
    @endif
    @unless(app(\App\Services\Orders\SupplierCostVisibility::class)->allowed())
        <x-catalog.product-section title="Supplier-based pricing"><small>Supplier costs are restricted. Ask an administrator for Supplier Prices → View permission.</small></x-catalog.product-section>
    @endunless
    @if($clientPriceTables->isNotEmpty())
        <x-catalog.product-section title="Client-specific pricing">
            @foreach($clientPriceTables as $clientPrice)
                <div class="ft-product-price-preview-wrap ft-product-detail-price-wrap" style="margin-bottom:14px">
                    <strong>{{ $clientPrice->client?->name ?? ('Client #'.$clientPrice->client_id) }}{{ $clientPrice->client?->code ? ' · '.$clientPrice->client->code : '' }}</strong>
                    <table class="ft-product-price-preview">
                        <thead><tr><th>Quantity</th>
                            @foreach((array) $clientPrice->price_breakpoints as $breakpoint)<th>{{ number_format((int) $breakpoint['quantity']) }}</th>@endforeach
                        </tr></thead><tbody><tr><th>Client price</th>
                            @foreach((array) $clientPrice->price_breakpoints as $breakpoint)<td>{{ (float) $breakpoint['price'] === 0.0 ? '0' : rtrim(rtrim(number_format((float) $breakpoint['price'], 6, '.', ''), '0'), '.') }}</td>@endforeach
                        </tr></tbody>
                    </table>
                </div>
            @endforeach
        </x-catalog.product-section>
    @endif
    @else
        <x-ui.progressive-section-loader section="pricing" method="loadProductDetailSection" key-prefix="product-detail" :rows="3" message="Loading product pricing when needed…" root-margin="360px 0px" />
    @endif

    @if($optionsReady)
    @if($productOptions->isNotEmpty() || $shipmentUrgencyOptions->isNotEmpty())
        <div class="ft-product-options-shipping-grid">
            @if($productOptions->isNotEmpty())
                <x-catalog.product-section title="Product options" class="ft-product-options-section ft-product-options-detail-section">
                    <div class="ft-product-option-detail-list">
                        @foreach($productOptions as $option)
                            <div class="ft-product-option-detail-row">
                                <strong>{{ $option['label'] }}</strong>
                                <div class="ft-product-option-detail-charge">
                                    <small>Extra charge</small>
                                    <span>{{ (float) ($option['extra_charge'] ?? 0) > 0 ? number_format((float) $option['extra_charge'], 2) : '0.00' }}</span>
                                </div>
                                <div class="ft-product-option-detail-image">
                                    @if($option['image_url'])<img src="{{ $option['image_url'] }}" alt="{{ $option['label'] }}">@else<span>No image</span>@endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-catalog.product-section>
            @endif

            @if($shipmentUrgencyOptions->isNotEmpty())
                <x-catalog.product-section title="Shipping urgencies" class="ft-product-shipping-detail-section">
                    <div class="ft-product-shipping-detail-table-wrap">
                        <table class="ft-product-shipping-detail-table">
                            <thead>
                                <tr>
                                    <th>Shipping urgency</th>
                                    <th>Code</th>
                                    <th>Extra charge</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($shipmentUrgencyOptions as $shipmentUrgencyOption)
                                    <tr>
                                        <td><strong>{{ $shipmentUrgencyOption['shipment_urgency_name'] ?: $shipmentUrgencyOption['shipment_urgency_code'] }}</strong></td>
                                        <td>{{ $shipmentUrgencyOption['shipment_urgency_code'] ?: '—' }}</td>
                                        <td>{{ (float) $shipmentUrgencyOption['extra_charge'] > 0 ? number_format((float) $shipmentUrgencyOption['extra_charge'], 2) : '0.00' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-catalog.product-section>
            @endif
        </div>
    @endif
    @else
        <x-ui.progressive-section-loader section="options" method="loadProductDetailSection" key-prefix="product-detail" :rows="3" message="Loading product options and shipping data when needed…" root-margin="320px 0px" />
    @endif

    @if($documentsReady)
    <x-catalog.product-section title="Certificates & documents" class="ft-product-documents-section">
        <div class="ft-product-documents-grid">
            <div class="ft-product-certificate-number"><small>Test certificate number</small><strong>{{ data_get($product->metadata, 'test_certificate_number') ?: '—' }}</strong></div>
            <x-catalog.product-document-card title="Certificate & Test Report" :document="$certificate" />
            <x-catalog.product-document-card title="Product template" :document="$template" />
        </div>
        <footer class="ft-product-audit-row" x-data="{activity:false}">
            <span>Created {{ $created?->format('M j, Y') ?? '—' }} by {{ $product->creator?->name ?? '—' }} <b>·</b> Last updated {{ $updated?->format('M j, Y') ?? '—' }}</span>
            <button type="button" x-on:click="activity=!activity">View activity</button>
            <div class="ft-product-activity-popover" x-cloak x-show="activity" x-on:click.outside="activity=false"><strong>Product activity</strong><span>Created {{ $created?->format('M j, Y g:i A') ?? '—' }} by {{ $product->creator?->name ?? '—' }}</span><span>Last updated {{ $updated?->format('M j, Y g:i A') ?? '—' }}</span></div>
        </footer>
    </x-catalog.product-section>
    @else
        <x-ui.progressive-section-loader section="documents" method="loadProductDetailSection" key-prefix="product-detail" :rows="3" message="Loading certificates and documents when needed…" root-margin="300px 0px" />
    @endif
</div>
