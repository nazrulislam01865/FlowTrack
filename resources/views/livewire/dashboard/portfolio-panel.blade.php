        <article class="ft-mgmt-panel ft-mgmt-client-panel" data-dashboard-panel="portfolio">
    <div class="ft-mgmt-dashboard-panel-swap" style="display:contents" wire:replace wire:key="dashboard-portfolio-content">
            <div class="ft-mgmt-panel-head"><div><h2>Client portfolio</h2><p>Activity and completion in the selected dashboard period</p></div><a class="ft-mgmt-link" href="{{ route('clients.index') }}" wire:navigate>All clients</a></div>
            <div class="ft-mgmt-panel-body ft-mgmt-client-portfolio-body">
                @if($clientPortfolio->isNotEmpty())
                    <div class="ft-mgmt-client-head" aria-hidden="true">
                        <span>Client</span>
                        <span>Inquiries</span>
                        <span>Orders</span>
                        <span>Created vs completed</span>
                    </div>
                @endif
                @forelse($clientPortfolio as $portfolioClient)
                    @php
                        $inquiries = (int) ($portfolioClient->inquiries_count ?? 0);
                        $orders = (int) ($portfolioClient->orders_count ?? 0);
                        $created = (int) ($portfolioClient->total_records_count ?? ($inquiries + $orders));
                        $completed = (int) ($portfolioClient->completed_records_count ?? 0);
                        $completion = $created > 0 ? min(100, max(0, (int) round(($completed / $created) * 100))) : 0;
                        $attention = (int) ($portfolioClient->attention_items_count ?? 0);
                    @endphp
                    <div class="ft-mgmt-client-row" wire:key="mgmt-client-{{ $portfolioClient->id }}">
                        <div class="ft-mgmt-client-name">
                            <span class="ft-mgmt-client-logo"><x-ui.client-logo :client="$portfolioClient" :name="$portfolioClient->name" :size="38" /></span>
                            <div class="ft-mgmt-client-copy">
                                <strong>{{ $portfolioClient->name }}</strong>
                                <span>{{ $attention }} attention item{{ $attention === 1 ? '' : 's' }}</span>
                            </div>
                        </div>
                        <b class="ft-mgmt-client-number">{{ $inquiries }}</b>
                        <b class="ft-mgmt-client-number">{{ $orders }}</b>
                        <div class="ft-mgmt-client-completion">
                            <div class="ft-mgmt-client-progress"><span style="width:{{ $completion }}%"></span></div>
                            <div>{{ $completed }} of {{ $created }} completed · {{ $completion }}%</div>
                        </div>
                    </div>
                @empty
                    <div class="ft-mgmt-empty">No client portfolio data matches the current filters.</div>
                @endforelse
            </div>
            </div>
</article>
