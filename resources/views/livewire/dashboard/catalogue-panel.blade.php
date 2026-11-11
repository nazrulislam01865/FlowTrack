        <article class="ft-mgmt-panel ft-mgmt-catalogue-side ft-mgmt-catalogue-prototype ft-mgmt-dashboard-half-panel" data-dashboard-panel="catalogue">
    <div class="ft-mgmt-dashboard-panel-swap" style="display:contents" wire:replace wire:key="dashboard-catalogue-content">
            <div class="ft-mgmt-catalogue-head">
                <div class="ft-mgmt-catalogue-title">
                    <h2>Catalogue readiness</h2>
                    <p>Product data, classification, availability and document coverage</p>
                </div>
                <div class="ft-mgmt-catalogue-actions">
                    <span class="ft-mgmt-catalogue-ready">{{ (int) ($catalogueReadiness['readyPercent'] ?? 0) }}% ready</span>
                    <a class="ft-mgmt-link" href="{{ route('master-data', ['group' => 'product']) }}" wire:navigate>Open catalogue</a>
                </div>
            </div>

            <div class="ft-mgmt-catalogue-body">
                <div class="ft-mgmt-catalogue-summary">
                    <span><strong>{{ number_format((int) ($catalogueReadiness['activeProducts'] ?? 0)) }}</strong> products</span>
                    <i aria-hidden="true"></i>
                    <span>{{ number_format((int) ($catalogueReadiness['mainCategories'] ?? 0)) }} main categories</span>
                    <i aria-hidden="true"></i>
                    <span>{{ number_format((int) ($catalogueReadiness['activeSuppliers'] ?? 0)) }} active {{ (int) ($catalogueReadiness['activeSuppliers'] ?? 0) === 1 ? 'supplier' : 'suppliers' }}</span>
                </div>

                <div class="ft-mgmt-catalogue-rows">
                    @foreach($catalogueReadiness['rows'] ?? [] as $row)
                        @php
                            $tone = in_array(($row['tone'] ?? ''), ['amber', 'green', 'red', 'blue'], true)
                                ? $row['tone']
                                : 'green';
                        @endphp
                        <div class="ft-mgmt-catalogue-row">
                            <div class="ft-mgmt-catalogue-label">
                                <span class="ft-mgmt-catalogue-dot {{ $tone }}" aria-hidden="true"></span>
                                <strong>{{ $row['label'] }}</strong>
                            </div>
                            <div class="ft-mgmt-catalogue-track" aria-label="{{ $row['label'] }} {{ (int) $row['value'] }} percent">
                                <span class="ft-mgmt-catalogue-fill {{ $tone }}" style="width:{{ max(0, min(100, (int) $row['value'])) }}%"></span>
                            </div>
                            <div class="ft-mgmt-catalogue-value">
                                <strong>{{ (int) $row['value'] }}%</strong>
                                <span>{{ $row['detail'] ?? '' }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            </div>
</article>
