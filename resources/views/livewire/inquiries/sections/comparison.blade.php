@php
    $submittedQuotes = $rfqInvitations
        ->filter(fn ($row) => $row->quote_status === 'submitted' && $row->quote)
        ->values();

    $winner = $rfqInvitations->first(fn ($row) => (bool) $row->awarded_at);
    $lowestTotal = $submittedQuotes->min(fn ($row) => (float) $row->quote->submitted_total);
    $fastestLeadTime = $submittedQuotes
        ->filter(fn ($row) => $row->quote->lead_time_days !== null)
        ->min(fn ($row) => (int) $row->quote->lead_time_days);

    $symbolFor = static fn (string $currency): string => match (strtoupper($currency)) {
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'CNY', 'RMB' => '¥',
        default => strtoupper($currency).' ',
    };

    $currency = (string) ($submittedQuotes->first()?->quote?->currency ?: $selectedInquiry->currency ?: 'USD');
    $inquiryItems = $selectedInquiry->items->values();
    $totalRequestedQuantity = (float) $inquiryItems->sum(fn ($item) => (float) $item->quantity);
    $normalisedProduct = $inquiryItems->count() === 1
        ? (string) ($inquiryItems->first()?->item_name ?: 'product')
        : number_format($inquiryItems->count()).' products';

    $defaultSelection = $winner ?: $submittedQuotes->first(
        fn ($row) => (float) $row->quote->submitted_total === (float) $lowestTotal
    ) ?: $submittedQuotes->first();
    $defaultSelectedId = $defaultSelection?->id;
    $defaultSelectedName = $defaultSelection?->supplier?->name ?: 'Supplier';

    $quoteSubtotal = static function ($quote): float {
        return (float) $quote->items->sum(
            fn ($item) => (float) $item->quantity * (float) $item->unit_price
        );
    };

    $weightedUnitPrice = static function ($quote) use ($quoteSubtotal): ?float {
        $quantity = (float) $quote->items->sum(fn ($item) => (float) $item->quantity);
        return $quantity > 0 ? $quoteSubtotal($quote) / $quantity : null;
    };

    $supplierMetaValue = static function ($invitation, array $keys): string {
        foreach ($keys as $key) {
            $raw = data_get($invitation->supplier?->metadata, $key);
            if (! is_scalar($raw)) continue;
            $value = trim((string) $raw);
            if ($value !== '') return $value;
        }
        return '—';
    };

    $tableMinWidth = 250 + max(1, $submittedQuotes->count()) * 300;
@endphp

<div class="ft-rfq-pane ft-rfq-comparison-pane" wire:key="inquiry-comparison-pane-{{ $selectedInquiry->id }}">
    <section
        class="ft-rfq-comparison-card"
        x-data="{
            selectedSupplierId: @js($defaultSelectedId),
            selectedSupplierName: @js($defaultSelectedName),
            shareComparison() {
                const payload = { title: 'Supplier comparison statement', url: window.location.href };
                if (navigator.share) {
                    navigator.share(payload).catch(() => {});
                    return;
                }
                window.prompt('Copy comparison link', window.location.href);
            }
        }"
    >
        <header class="ft-rfq-comparison-head">
            <div class="ft-rfq-comparison-heading">
                <h2>Supplier comparison statement</h2>
                <p>
                    Normalised for {{ number_format($totalRequestedQuantity, 0) }} units of {{ $normalisedProduct }}
                    <span aria-hidden="true">·</span> {{ strtoupper($currency) }}
                </p>
            </div>

            @if(!$submittedQuotes->isEmpty())
                <div class="ft-rfq-comparison-head-actions" aria-label="Comparison actions">
                    <button type="button" class="ft-rfq-comparison-secondary-btn" x-on:click="window.print()">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 19h14"/></svg>
                        <span>Export</span>
                    </button>
                    <button type="button" class="ft-rfq-comparison-secondary-btn" x-on:click="shareComparison()">Share</button>
                </div>
            @endif
        </header>

        @error('rfqAward')
            <div class="ft-rfq-inline-error">{{ $message }}</div>
        @enderror

        @if($submittedQuotes->isEmpty())
            <div class="ft-rfq-comparison-empty">
                <strong>No quotations received yet</strong>
                <span>Submitted supplier quotations will appear here automatically.</span>
                <button type="button" class="ft-outline-btn" wire:click="setDetailTab('rfq')">Back to RFQ</button>
            </div>
        @else
            <div class="ft-rfq-comparison-scroll" role="region" aria-label="Supplier comparison" tabindex="0">
                <table class="ft-rfq-comparison-matrix" style="min-width: {{ $tableMinWidth }}px">
                    <colgroup>
                        <col class="ft-rfq-comparison-criteria-col">
                        @foreach($submittedQuotes as $invitation)
                            <col class="ft-rfq-comparison-supplier-col">
                        @endforeach
                    </colgroup>
                    <thead>
                        <tr>
                            <th scope="col" class="ft-rfq-comparison-criteria-head">Comparison criteria</th>
                            @foreach($submittedQuotes as $invitation)
                                <th
                                    scope="col"
                                    class="ft-rfq-comparison-supplier-head"
                                    :class="{ 'is-selected': selectedSupplierId === {{ $invitation->id }} }"
                                    wire:key="comparison-head-{{ $invitation->id }}"
                                >
                                    <strong>{{ \Illuminate\Support\Str::upper($invitation->supplier?->name ?: 'Supplier') }}</strong>
                                    @if((float) $invitation->quote->submitted_total === (float) $lowestTotal)
                                        <span class="ft-rfq-comparison-badge is-best">Best value</span>
                                    @elseif(
                                        $invitation->quote->lead_time_days !== null
                                        && $fastestLeadTime !== null
                                        && (int) $invitation->quote->lead_time_days === (int) $fastestLeadTime
                                    )
                                        <span class="ft-rfq-comparison-badge is-fastest">Fastest</span>
                                    @else
                                        <span class="ft-rfq-comparison-badge is-participant">RFQ participant</span>
                                    @endif
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <th scope="row">Select supplier</th>
                            @foreach($submittedQuotes as $invitation)
                                <td :class="{ 'is-selected': selectedSupplierId === {{ $invitation->id }} }">
                                    <label class="ft-rfq-comparison-radio">
                                        <input
                                            type="radio"
                                            name="rfq-comparison-supplier-{{ $selectedInquiry->id }}"
                                            value="{{ $invitation->id }}"
                                            x-model.number="selectedSupplierId"
                                            x-on:change="selectedSupplierName = @js($invitation->supplier?->name ?: 'Supplier')"
                                            @disabled($winner || !$canManageInquiryRfq)
                                        >
                                        <span>{{ $invitation->awarded_at ? 'Selected' : 'Select' }}</span>
                                    </label>
                                </td>
                            @endforeach
                        </tr>

                        <tr>
                            <th scope="row">Unit price</th>
                            @foreach($submittedQuotes as $invitation)
                                <td :class="{ 'is-selected': selectedSupplierId === {{ $invitation->id }} }">
                                    <strong class="ft-rfq-comparison-price">
                                        {{ $weightedUnitPrice($invitation->quote) !== null
                                            ? $symbolFor((string) $invitation->quote->currency).number_format($weightedUnitPrice($invitation->quote), 2)
                                            : '—' }}
                                    </strong>
                                    <small>
                                        {{ $symbolFor((string) $invitation->quote->currency) }}{{ number_format($quoteSubtotal($invitation->quote), 2) }} subtotal
                                    </small>
                                </td>
                            @endforeach
                        </tr>

                        <tr>
                            <th scope="row">Freight</th>
                            @foreach($submittedQuotes as $invitation)
                                <td :class="{ 'is-selected': selectedSupplierId === {{ $invitation->id }} }">{{ $symbolFor((string) $invitation->quote->currency) }}{{ number_format((float) $invitation->quote->freight, 2) }}</td>
                            @endforeach
                        </tr>

                        <tr>
                            <th scope="row">Landed total</th>
                            @foreach($submittedQuotes as $invitation)
                                <td :class="{ 'is-selected': selectedSupplierId === {{ $invitation->id }} }"><strong class="ft-rfq-comparison-total">{{ $symbolFor((string) $invitation->quote->currency) }}{{ number_format((float) $invitation->quote->submitted_total, 2) }}</strong></td>
                            @endforeach
                        </tr>

                        <tr>
                            <th scope="row">Lead time</th>
                            @foreach($submittedQuotes as $invitation)
                                <td :class="{ 'is-selected': selectedSupplierId === {{ $invitation->id }} }">{{ $invitation->quote->lead_time_days !== null ? number_format($invitation->quote->lead_time_days).' days' : '—' }}</td>
                            @endforeach
                        </tr>

                        <tr>
                            <th scope="row">MOQ</th>
                            @foreach($submittedQuotes as $invitation)
                                <td :class="{ 'is-selected': selectedSupplierId === {{ $invitation->id }} }">@forelse($invitation->quote->items as $line)<div>{{ $line->product_name }}: {{ $line->moq ?? '—' }}</div>@empty — @endforelse</td>
                            @endforeach
                        </tr>

                        <tr>
                            <th scope="row">Payment terms</th>
                            @foreach($submittedQuotes as $invitation)
                                <td :class="{ 'is-selected': selectedSupplierId === {{ $invitation->id }} }">{{ $supplierMetaValue($invitation, ['payment_terms', 'supplier_payment_terms']) }}</td>
                            @endforeach
                        </tr>

                        <tr>
                            <th scope="row">Sample</th>
                            @foreach($submittedQuotes as $invitation)
                                <td :class="{ 'is-selected': selectedSupplierId === {{ $invitation->id }} }">{{ $symbolFor((string)$invitation->quote->currency) }}{{ number_format((float)$invitation->quote->sample_cost,2) }}</td>
                            @endforeach
                        </tr>

                        <tr>
                            <th scope="row">Quoted items</th>
                            @foreach($submittedQuotes as $invitation)
                                <td :class="{ 'is-selected': selectedSupplierId === {{ $invitation->id }} }">
                                    @foreach($invitation->quote->items as $line)
                                        <div>{{ $line->product_name }} · {{ $line->quantity }} × {{ $invitation->quote->currency }} {{ number_format((float)$line->unit_price, 2) }}</div>
                                    @endforeach
                                </td>
                            @endforeach
                        </tr>
                        <tr>
                            <th scope="row">Commercial terms</th>
                            @foreach($submittedQuotes as $invitation)
                                <td :class="{ 'is-selected': selectedSupplierId === {{ $invitation->id }} }">
                                    <div>Tooling: {{ $invitation->quote->currency }} {{ number_format((float)$invitation->quote->tooling_cost,2) }}</div>
                                    <div>Tax: {{ ucfirst((string)$invitation->quote->tax_status) }}</div>
                                    <div>Incoterm: {{ $invitation->quote->incoterm ?: '—' }}</div>
                                    <div>Shipping port: {{ $invitation->quote->shipping_port ?: '—' }}</div>
                                    <div>Validity: {{ $invitation->quote->validity_days ?? '—' }} days</div>
                                    <div>Compliance: {{ $invitation->quote->specification_compliance ?: '—' }}</div>
                                    @if($invitation->quote->sample_lead_time_days !== null)
                                        <div>Sample lead: {{ $invitation->quote->sample_lead_time_days }} days</div>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                        <tr>
                            <th scope="row">Volume price breaks</th>
                            @foreach($submittedQuotes as $invitation)
                                <td :class="{ 'is-selected': selectedSupplierId === {{ $invitation->id }} }">
                                    @forelse((array)data_get($invitation->quote->portal_details, 'price_breaks', []) as $break)
                                        <div>{{ $break['quantity'] ?? '—' }} units: {{ $invitation->quote->currency }} {{ $break['unit_price'] ?? '—' }}</div>
                                    @empty — @endforelse
                                </td>
                            @endforeach
                        </tr>

                        <tr class="ft-rfq-comparison-attachments-row">
                            <th scope="row">Attachments</th>
                            @foreach($submittedQuotes as $invitation)
                                <td :class="{ 'is-selected': selectedSupplierId === {{ $invitation->id }} }">
                                    @forelse($invitation->quote->documents as $quoteDocument)
                                        <a href="{{ route('inquiries.rfq-quote-documents.download', ['document' => $quoteDocument->id]) }}" class="ft-rfq-comparison-attachment" style="display:block;margin:3px 0;text-decoration:underline" title="Download {{ $quoteDocument->name }}">
                                            {{ $quoteDocument->name }}
                                        </a>
                                    @empty
                                        <span class="ft-rfq-comparison-empty-value">—</span>
                                    @endforelse
                                </td>
                            @endforeach
                        </tr>

                        <tr class="ft-rfq-comparison-note-row">
                            <th scope="row">Supplier note</th>
                            @foreach($submittedQuotes as $invitation)
                                <td :class="{ 'is-selected': selectedSupplierId === {{ $invitation->id }} }">{{ $invitation->quote->notes ?: '—' }}</td>
                            @endforeach
                        </tr>
                    </tbody>
                </table>
            </div>

            <footer class="ft-rfq-comparison-award-bar">
                <div class="ft-rfq-comparison-selection-copy">
                    @if($winner)
                        <strong>{{ $winner->supplier?->name ?: 'Supplier' }} selected</strong>
                        <span>This supplier has already been awarded for the inquiry.</span>
                    @else
                        <strong><span x-text="selectedSupplierName"></span> selected</strong>
                        <span>Review the commercial terms and submitted lead time before awarding.</span>
                    @endif
                </div>

                @if($winner)
                    <button type="button" class="ft-rfq-comparison-award-btn" disabled>Awarded</button>
                @elseif($canManageInquiryRfq)
                    <button
                        type="button"
                        class="ft-rfq-comparison-award-btn"
                        x-bind:disabled="!selectedSupplierId"
                        x-on:click="if (selectedSupplierId && window.confirm('Award selected supplier? The selected supplier will be linked to the Inquiry products and the other invited suppliers will be notified.')) { $wire.awardRfqSupplier(selectedSupplierId) }"
                        wire:loading.attr="disabled"
                        wire:target="awardRfqSupplier"
                    >
                        <span wire:loading.remove wire:target="awardRfqSupplier">Award selected supplier</span>
                        <span wire:loading wire:target="awardRfqSupplier">Awarding...</span>
                    </button>
                @endif
            </footer>
        @endif
    </section>
</div>
