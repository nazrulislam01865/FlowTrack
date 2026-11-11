@if($kind === 'distributionpanel')
    <section class="ft-mgmt-dashboard-distribution-shell ft-mgmt-dashboard-skeleton" data-dashboard-skeleton-for="distribution" role="status" aria-busy="true" aria-label="Loading workflow and task status distributions">
    <div class="ft-mgmt-grid ft-mgmt-dashboard-pair-grid ft-mgmt-dashboard-panel-swap" wire:replace wire:key="dashboard-distribution-content">
        <article class="ft-mgmt-panel ft-mgmt-flow-prototype" wire:intersect.once="loadDashboardPanel">
            <div class="ft-mgmt-panel-head">
                <div><h2>Work moving through FlowTrack</h2></div>
                <div class="ft-mgmt-skeleton-tabs" aria-hidden="true"><span class="ft-mgmt-skeleton-line"></span><span class="ft-mgmt-skeleton-line"></span></div>
            </div>
            <div class="ft-mgmt-panel-body ft-mgmt-skeleton-body" aria-hidden="true">
                @for($i = 0; $i < 6; $i++)
                    <div class="ft-mgmt-skeleton-chart-row"><span class="ft-mgmt-skeleton-line"></span><span class="ft-mgmt-skeleton-line"></span><span class="ft-mgmt-skeleton-line"></span></div>
                @endfor
            </div>
        </article>
        <article class="ft-mgmt-panel">
            <div class="ft-mgmt-panel-head"><div><h2>Task status distribution</h2></div></div>
            <div class="ft-mgmt-panel-body ft-mgmt-skeleton-distribution" aria-hidden="true">
                <div class="ft-mgmt-skeleton-donut"></div>
                <div class="ft-mgmt-skeleton-legend">
                    @for($i = 0; $i < 5; $i++)
                        <span class="ft-mgmt-skeleton-line"></span>
                    @endfor
                </div>
            </div>
        </article>
        <span class="sr-only">Loading dashboard distributions</span>
        </div>
</section>
@elseif($kind === 'cataloguepanel')
    <article class="ft-mgmt-panel ft-mgmt-catalogue-side ft-mgmt-catalogue-prototype ft-mgmt-dashboard-half-panel ft-mgmt-dashboard-skeleton" data-dashboard-skeleton-for="catalogue" role="status" aria-busy="true" aria-label="Loading Catalogue readiness">
    <div class="ft-mgmt-dashboard-panel-swap" style="display:contents" wire:replace wire:key="dashboard-catalogue-content">
        <div class="ft-mgmt-catalogue-head" wire:intersect.once="loadDashboardPanel"><div class="ft-mgmt-catalogue-title"><h2>{{ $heading }}</h2></div></div>
        <div class="ft-mgmt-catalogue-body ft-mgmt-skeleton-body" aria-hidden="true">
            <div class="ft-mgmt-skeleton-catalogue-summary">
                <span class="ft-mgmt-skeleton-line"></span><span class="ft-mgmt-skeleton-line"></span><span class="ft-mgmt-skeleton-line"></span>
            </div>
            @for($i = 0; $i < 5; $i++)
                <div class="ft-mgmt-skeleton-chart-row"><span class="ft-mgmt-skeleton-line"></span><span class="ft-mgmt-skeleton-line"></span><span class="ft-mgmt-skeleton-line"></span></div>
            @endfor
        </div>
        <span class="sr-only">Loading Catalogue readiness</span>
        </div>
</article>
@else
    <article class="ft-mgmt-panel ft-mgmt-dashboard-skeleton {{ match($kind) {
        'prioritypanel' => 'ft-mgmt-panel-spaced',
        'attentionpanel' => 'ft-mgmt-attention-prototype ft-mgmt-attention-compact ft-mgmt-dashboard-half-panel',
        'teampanel' => 'ft-mgmt-team-panel ft-mgmt-panel-spaced',
        'portfoliopanel' => 'ft-mgmt-client-panel',
        'activitypanel' => 'ft-mgmt-recent-prototype',
        default => '',
    } }}" data-dashboard-skeleton-for="{{ str_replace('panel', '', $kind) }}" style="min-height:{{ $height }}px" role="status" aria-busy="true" aria-label="Loading {{ $heading }}">
    <div class="ft-mgmt-dashboard-panel-swap" style="display:contents" wire:replace wire:key="dashboard-{{ str_replace('panel', '', $kind) }}-content">
        <div class="ft-mgmt-panel-head" wire:intersect.once="loadDashboardPanel">
            <div><h2>{{ $heading }}</h2></div>
            @if(in_array($kind, ['prioritypanel', 'attentionpanel', 'activitypanel'], true))
                <div class="ft-mgmt-skeleton-tabs" aria-hidden="true">
                    @for($i = 0; $i < ($kind === 'attentionpanel' ? 2 : ($kind === 'activitypanel' ? 4 : 3)); $i++)
                        <span class="ft-mgmt-skeleton-line"></span>
                    @endfor
                </div>
            @endif
        </div>
        @if($kind === 'prioritypanel')
            <div class="ft-mgmt-skeleton-table" aria-hidden="true">
                <div class="ft-mgmt-skeleton-table-row ft-mgmt-skeleton-table-head">
                    @for($i = 0; $i < 7; $i++)<span class="ft-mgmt-skeleton-line"></span>@endfor
                </div>
                @for($row = 0; $row < 5; $row++)
                    <div class="ft-mgmt-skeleton-table-row">
                        @for($i = 0; $i < 7; $i++)<span class="ft-mgmt-skeleton-line"></span>@endfor
                    </div>
                @endfor
            </div>
        @elseif($kind === 'teampanel')
            <div class="ft-mgmt-panel-body ft-mgmt-skeleton-team" aria-hidden="true">
                @for($i = 0; $i < 4; $i++)
                    <div class="ft-mgmt-skeleton-person">
                        <div class="ft-mgmt-skeleton-person-head"><span class="ft-mgmt-skeleton-circle"></span><span class="ft-mgmt-skeleton-line"></span></div>
                        <span class="ft-mgmt-skeleton-line"></span><span class="ft-mgmt-skeleton-line"></span>
                        <span class="ft-mgmt-skeleton-line ft-mgmt-skeleton-progress"></span>
                    </div>
                @endfor
            </div>
        @elseif($kind === 'portfoliopanel')
            <div class="ft-mgmt-panel-body ft-mgmt-skeleton-body" aria-hidden="true">
                @for($i = 0; $i < 4; $i++)
                    <div class="ft-mgmt-skeleton-portfolio-row"><span class="ft-mgmt-skeleton-circle"></span><span class="ft-mgmt-skeleton-line"></span><span class="ft-mgmt-skeleton-line"></span><span class="ft-mgmt-skeleton-line"></span></div>
                @endfor
            </div>
        @else
            <div class="ft-mgmt-panel-body ft-mgmt-skeleton-body" aria-hidden="true">
                @for($i = 0; $i < ($kind === 'activitypanel' ? 5 : 4); $i++)
                    <div class="ft-mgmt-skeleton-feed-row">
                        <span class="ft-mgmt-skeleton-circle"></span>
                        <div class="ft-mgmt-skeleton-feed-copy"><span class="ft-mgmt-skeleton-line"></span><span class="ft-mgmt-skeleton-line"></span></div>
                        <span class="ft-mgmt-skeleton-line ft-mgmt-skeleton-time"></span>
                    </div>
                @endfor
            </div>
        @endif
        <span class="sr-only">Loading {{ $heading }}</span>
        </div>
</article>
@endif
