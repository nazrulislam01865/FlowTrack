@props([
    'invitation', 'token', 'quote', 'products', 'currency', 'contact', 'rfqReference', 'clientName',
    'documents' => [], 'locked' => false,
])
@php
    $inquiry = $invitation->inquiry;
    $quoteItems = collect($quote?->items ?? [])->keyBy('inquiry_item_id');
    $documentCollection = collect($documents);
    $dueLabel = $invitation->due_at?->format('M j, Y · g:i A') ?? 'No due date';
@endphp

<div class="ft-rfq-pricing-prototype">
    <form method="post" action="{{ route('rfq.public.respond', ['token' => $token]) }}" id="rfq-pricing-form" class="ft-rfq-pricing-prototype__form" enctype="multipart/form-data">
        @csrf

        <section class="ft-rfq-portal-card ft-rfq-prototype-card ft-rfq-prototype-details" aria-labelledby="rfq-pricing-details-title">
            <div class="ft-rfq-prototype-section-head">
                <span class="ft-rfq-prototype-section-number">1</span>
                <h2 id="rfq-pricing-details-title">RFQ and supplier details</h2>
            </div>

            <div class="ft-rfq-prototype-details-grid is-reference-row">
                <label class="ft-rfq-prototype-field is-readonly">
                    <span>Inquiry reference</span>
                    <span class="ft-rfq-prototype-control-with-icon">
                        <input type="text" value="{{ $inquiry->inquiry_number }}" readonly tabindex="-1">
                        <x-rfq.public.icon name="lock" />
                    </span>
                </label>
                <label class="ft-rfq-prototype-field is-readonly">
                    <span>RFQ reference</span>
                    <span class="ft-rfq-prototype-control-with-icon">
                        <input type="text" value="{{ $rfqReference }}" readonly tabindex="-1">
                        <x-rfq.public.icon name="lock" />
                    </span>
                </label>
                <label class="ft-rfq-prototype-field is-readonly">
                    <span>Requested by</span>
                    <span class="ft-rfq-prototype-control-with-icon">
                        <input type="text" value="{{ $clientName }}" readonly tabindex="-1">
                        <x-rfq.public.icon name="lock" />
                    </span>
                </label>
                <label class="ft-rfq-prototype-field is-readonly">
                    <span>Quotation due</span>
                    <span class="ft-rfq-prototype-control-with-icon">
                        <input type="text" value="{{ $dueLabel }}" readonly tabindex="-1">
                        <x-rfq.public.icon name="lock" />
                    </span>
                </label>
            </div>

            <div class="ft-rfq-prototype-details-grid is-contact-row">
                <label class="ft-rfq-prototype-field">
                    <span>Supplier company <b>*</b></span>
                    <input type="text" value="{{ $invitation->supplier?->name ?: '—' }}" readonly tabindex="-1">
                </label>
                <label class="ft-rfq-prototype-field">
                    <span>Contact person <b>*</b></span>
                    <input type="text" name="supplier_contact_name" value="{{ old('supplier_contact_name', $contact['name'] ?? '') }}" required @disabled($locked)>
                </label>
                <label class="ft-rfq-prototype-field">
                    <span>Email <b>*</b></span>
                    <input type="email" name="supplier_contact_email" value="{{ old('supplier_contact_email', $contact['email'] ?? '') }}" required @disabled($locked)>
                </label>
                <div class="ft-rfq-prototype-phone-cell">
                    <label class="ft-rfq-prototype-field">
                        <span>Phone <b>*</b></span>
                        <input type="text" name="supplier_contact_phone" value="{{ old('supplier_contact_phone', $contact['phone'] ?? '') }}" @disabled($locked)>
                    </label>
                    @unless($locked)
                        <button type="button" class="ft-rfq-edit-contact-link" data-rfq-edit-contact>
                            <x-rfq.public.icon name="pencil" /> Edit contact details
                        </button>
                    @endunless
                </div>
            </div>
        </section>

        <x-rfq.public.product-to-quote :invitation="$invitation" :token="$token" :products="$products" />

        <section class="ft-rfq-portal-card ft-rfq-prototype-card ft-rfq-prototype-pricing" aria-labelledby="rfq-pricing-section-title">
            <div class="ft-rfq-prototype-section-head is-inline-copy">
                <span class="ft-rfq-prototype-section-number">3</span>
                <h2 id="rfq-pricing-section-title">Pricing</h2>
                <p>Enter pricing for the requested quantity. You may add volume price breaks.</p>
            </div>

            <div class="ft-rfq-prototype-price-lines">
                @foreach($products as $product)
                    @php
                        $item = $quoteItems->get($product['item_id']);
                        $initialPrice = old('prices.'.$product['item_id'], $item?->unit_price);
                        $initialSubtotal = (float) $product['quantity'] * (float) ($initialPrice ?? 0);
                    @endphp
                    <div class="ft-rfq-prototype-price-line" data-rfq-price-row data-quantity="{{ (float) $product['quantity'] }}">
                        <label class="ft-rfq-prototype-field">
                            <span>Quantity</span>
                            <input type="text" value="{{ number_format((float) $product['quantity'], fmod((float) $product['quantity'], 1.0) === 0.0 ? 0 : 2) }}" readonly tabindex="-1">
                        </label>
                        <label class="ft-rfq-prototype-field">
                            <span>Currency</span>
                            <select name="currency" data-rfq-currency @disabled($locked)>
                                @foreach(['USD' => 'USD', 'EUR' => 'EUR', 'GBP' => 'GBP', 'CNY' => 'CNY'] as $code => $label)
                                    <option value="{{ $code }}" @selected(old('currency', $currency) === $code)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="ft-rfq-prototype-field">
                            <span>Unit price (<span data-rfq-currency-label>{{ $currency }}</span>) <b>*</b></span>
                            <input type="number" name="prices[{{ $product['item_id'] }}]" value="{{ $initialPrice }}" min="0" step="0.0001" required data-rfq-price @disabled($locked)>
                        </label>
                        <label class="ft-rfq-prototype-field">
                            <span>MOQ <b>*</b></span>
                            <input type="number" name="moqs[{{ $product['item_id'] }}]" value="{{ old('moqs.'.$product['item_id'], $item?->moq) }}" min="0" step="1" @disabled($locked)>
                        </label>
                        <div class="ft-rfq-prototype-subtotal">
                            <span>Subtotal (<span data-rfq-currency-label>{{ $currency }}</span>)</span>
                            <strong data-rfq-line-subtotal>{{ $currency }} {{ number_format($initialSubtotal, 2) }}</strong>
                            <small>Calculated automatically</small>
                        </div>
                        @if($loop->first && !$locked)
                            <button type="button" class="ft-rfq-add-price-break-btn" data-rfq-add-price-break title="Add an optional quantity tier with a different unit price" aria-label="Add an optional volume price break">
                                <span>+</span>
                                <span class="ft-rfq-add-price-break-copy">
                                    <strong>Add price break</strong>
                                    <small>Optional quantity tier</small>
                                </span>
                            </button>
                        @else
                            <span></span>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="ft-rfq-prototype-costs-heading">
                <strong>Additional costs &amp; tax</strong>
                <span>Add only the charges that apply to this quotation.</span>
            </div>

            <div class="ft-rfq-prototype-cost-row">
                <label class="ft-rfq-prototype-field">
                    <span>Tooling / setup cost (<span data-rfq-currency-label>{{ $currency }}</span>)</span>
                    <input type="number" name="tooling_cost" value="{{ old('tooling_cost', $quote?->tooling_cost ?? 0) }}" min="0" step="0.01" @disabled($locked)>
                </label>
                <label class="ft-rfq-prototype-field">
                    <span>Sample cost (<span data-rfq-currency-label>{{ $currency }}</span>)</span>
                    <input type="number" name="sample_cost" value="{{ old('sample_cost', $quote?->sample_cost ?? 0) }}" min="0" step="0.01" @disabled($locked)>
                </label>
                <label class="ft-rfq-prototype-field">
                    <span>Discount (<span data-rfq-currency-label>{{ $currency }}</span>)</span>
                    <input type="number" name="discount" value="{{ old('discount', $quote?->discount ?? 0) }}" min="0" step="0.01" @disabled($locked)>
                </label>
                <label class="ft-rfq-prototype-field">
                    <span>Tax <b>*</b></span>
                    <select name="tax_status" @disabled($locked)>
                        <option value="excluded" @selected(old('tax_status', $quote?->tax_status ?? 'excluded') === 'excluded')>Excluded</option>
                        <option value="included" @selected(old('tax_status', $quote?->tax_status) === 'included')>Included</option>
                    </select>
                </label>
                <input type="hidden" name="freight" value="{{ old('freight', $quote?->freight ?? 0) }}">
            </div>
        </section>

        <section class="ft-rfq-portal-card ft-rfq-prototype-card ft-rfq-prototype-production" aria-labelledby="rfq-production-title">
            <div class="ft-rfq-prototype-section-head">
                <span class="ft-rfq-prototype-section-number">4</span>
                <h2 id="rfq-production-title">Production and delivery</h2>
            </div>

            <div class="ft-rfq-prototype-production-grid">
                <label class="ft-rfq-prototype-field">
                    <span>Production lead time <b>*</b></span>
                    <span class="ft-rfq-prototype-suffix-control"><input type="number" name="lead_time_days" value="{{ old('lead_time_days', $quote?->lead_time_days) }}" min="0" @disabled($locked)><em>days</em></span>
                </label>
                <label class="ft-rfq-prototype-field">
                    <span>Sample lead time <b>*</b></span>
                    <span class="ft-rfq-prototype-suffix-control"><input type="number" name="sample_lead_time_days" value="{{ old('sample_lead_time_days', $quote?->sample_lead_time_days) }}" min="0" @disabled($locked)><em>days</em></span>
                </label>
                <label class="ft-rfq-prototype-field">
                    <span>Incoterm <b>*</b></span>
                    <select name="incoterm" @disabled($locked)>
                        @foreach(['FOB', 'EXW', 'FCA', 'CIF', 'CFR', 'DAP', 'DDP'] as $incoterm)
                            <option value="{{ $incoterm }}" @selected(old('incoterm', $quote?->incoterm ?: 'FOB') === $incoterm)>{{ $incoterm }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="ft-rfq-prototype-field">
                    <span>Shipping port <b>*</b></span>
                    <input type="text" name="shipping_port" value="{{ old('shipping_port', $quote?->shipping_port) }}" placeholder="Shanghai" @disabled($locked)>
                </label>
                <label class="ft-rfq-prototype-field is-deviations">
                    <span>Deviations or alternatives (optional)</span>
                    <textarea rows="4" name="notes" placeholder="Describe any differences from the requested specification..." @disabled($locked)>{{ old('notes', $quote?->notes) }}</textarea>
                </label>
                <label class="ft-rfq-prototype-field">
                    <span>Estimated delivery date <b>*</b></span>
                    <input type="date" name="estimated_delivery_date" value="{{ old('estimated_delivery_date', $quote?->estimated_delivery_date?->format('Y-m-d')) }}" @disabled($locked)>
                </label>
                <label class="ft-rfq-prototype-field">
                    <span>Quote validity <b>*</b></span>
                    <span class="ft-rfq-prototype-suffix-control"><input type="number" name="validity_days" value="{{ old('validity_days', $quote?->validity_days ?? 30) }}" min="0" @disabled($locked)><em>days</em></span>
                </label>
                <label class="ft-rfq-prototype-field is-specification">
                    <span>Can meet requested specification? <b>*</b></span>
                    <select name="specification_compliance" @disabled($locked)>
                        <option value="">Select</option>
                        <option value="yes" @selected(old('specification_compliance', $quote?->specification_compliance) === 'yes')>Yes, fully compliant</option>
                        <option value="partial" @selected(old('specification_compliance', $quote?->specification_compliance) === 'partial')>Partially compliant</option>
                        <option value="no" @selected(old('specification_compliance', $quote?->specification_compliance) === 'no')>Not compliant</option>
                    </select>
                </label>
            </div>
        </section>

        <section class="ft-rfq-portal-card ft-rfq-prototype-card ft-rfq-prototype-supporting" aria-labelledby="rfq-supporting-title">
            <div class="ft-rfq-prototype-section-head">
                <span class="ft-rfq-prototype-section-number">5</span>
                <h2 id="rfq-supporting-title">Supporting documents</h2>
            </div>

            <div class="ft-rfq-prototype-supporting-grid">
                <label class="ft-rfq-prototype-dropzone" data-rfq-dropzone>
                    <input
                        type="file"
                        name="documents[]"
                        multiple
                        accept="{{ \App\Support\AttachmentUpload::accept() }}"
                        data-rfq-pricing-file-input
                        @disabled($locked)
                    >
                    <span class="ft-rfq-prototype-upload-icon"><x-rfq.public.icon name="upload-cloud" /></span>
                    <span>
                        <strong>Drop quotation files here or <b>browse</b></strong>
                        <small>{{ \App\Support\AttachmentUpload::helperText(20) }}</small>
                    </span>
                </label>

                <div class="ft-rfq-prototype-supporting-right">
                    <div class="ft-rfq-prototype-uploaded-files">
                        @forelse($documentCollection as $document)
                            @php
                                $extension = strtolower(pathinfo((string) $document->name, PATHINFO_EXTENSION));
                                $size = (int) $document->size;
                                $sizeLabel = $size >= 1048576 ? number_format($size / 1048576, 1).' MB' : number_format(max(1, (int) ceil($size / 1024)), 0).' KB';
                            @endphp
                            <div class="ft-rfq-prototype-file-row">
                                <span class="ft-rfq-file-icon is-{{ $extension }}">{{ strtoupper(substr($extension ?: 'FILE', 0, 4)) }}</span>
                                <span class="ft-rfq-prototype-file-name">{{ $document->name }}</span>
                                <span class="ft-rfq-prototype-file-size">·&nbsp; {{ $sizeLabel }}</span>
                                <span class="ft-rfq-prototype-file-ready">✓</span>
                                @unless($locked)
                                    <button type="submit" form="rfq-pricing-remove-doc-{{ $document->id }}" class="ft-rfq-prototype-remove-file">Remove</button>
                                @endunless
                            </div>
                        @empty
                            <div class="ft-rfq-prototype-empty-file">No quotation file uploaded yet.</div>
                        @endforelse
                    </div>

                    <label class="ft-rfq-prototype-field ft-rfq-prototype-supplier-notes">
                        <span>Supplier notes (optional)</span>
                        <input type="text" name="document_notes" value="{{ old('document_notes', $quote?->document_notes) }}" placeholder="Pricing includes standard export packaging. Samples available within 5 days." @disabled($locked)>
                    </label>
                </div>
            </div>
        </section>

        <section class="ft-rfq-prototype-confirmation" aria-label="Quotation confirmation">
            <label>
                <input type="checkbox" name="pricing_confirmation" value="1" data-rfq-pricing-confirmation @disabled($locked)>
                <span>I confirm that the information provided is accurate and that this quotation is valid for the stated period. <b>*</b></span>
            </label>
            @unless($locked)<p><x-rfq.public.icon name="alert" /> Required before submission</p>@endunless
            <small>You can save a draft and return using the same secure link before the deadline.</small>
        </section>
    </form>

    @unless($locked)
        @foreach($documentCollection as $document)
            <form method="post" action="{{ route('rfq.public.documents.remove', ['token' => $token, 'document' => $document->id]) }}" id="rfq-pricing-remove-doc-{{ $document->id }}" class="ft-rfq-hidden-form">
                @csrf
                <input type="hidden" name="return_step" value="pricing">
            </form>
        @endforeach
    @endunless
</div>
