    @php
        $teamReportParams = array_filter([
            // Keep "View all" aligned with the dashboard's global period. The
            // full report still supports its own reporting-period controls after
            // the user leaves the dashboard.
            'period' => 'custom',
            'from' => $teamReportingPeriod['from'] ?? null,
            'to' => $teamReportingPeriod['to'] ?? null,
            'client' => $clientFilter,
            'department' => $teamFilter,
            'q' => $search,
        ], static fn ($value) => $value !== null && $value !== '');
    @endphp
    <article class="ft-mgmt-panel ft-mgmt-team-panel ft-mgmt-panel-spaced" data-dashboard-panel="team">
    <div class="ft-mgmt-dashboard-panel-swap" style="display:contents" wire:replace wire:key="dashboard-team-content">
        <div class="ft-mgmt-panel-head ft-mgmt-team-panel-head">
            <div>
                <h2>Team performance &amp; workload</h2>
                <p>Top 4 assignees from actual Inquiry and Order task records · {{ $teamReportingPeriod['label'] ?? 'Last 7 days' }}.</p>
            </div>
            <a class="ft-mgmt-btn ft-mgmt-team-view-all" href="{{ route('team-performance.report', $teamReportParams) }}" wire:navigate>View all</a>
        </div>
        <div class="ft-mgmt-panel-body">
            <div class="ft-mgmt-team-grid">
                @forelse($assigneePerformance as $person)
                    <x-dashboard.team-performance-card :person="$person" wire:key="mgmt-person-{{ $person->id }}" />
                @empty
                    <div class="ft-mgmt-empty">No team workload matches the current dashboard filters and period.</div>
                @endforelse
            </div>
        </div>
        </div>
</article>
