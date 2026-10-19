<x-ui.management-theme class="ft-mgmt-dashboard ft-mgmt-team-report-page">
    <div class="ft-mgmt-page-head ft-mgmt-team-report-head">
        <div>
            <a class="ft-mgmt-team-report-back" href="{{ route('dashboard') }}" wire:navigate>← Dashboard</a>
            <h1>Team Performance Report</h1>
            <p>All user performance from actual Inquiry and Order task records in the selected reporting period.</p>
        </div>
    </div>

    <section class="ft-mgmt-panel ft-mgmt-team-report-filter-panel" aria-label="Team performance report filters">
        <div class="ft-mgmt-panel-body">
            <div class="ft-mgmt-team-report-filters">
                <label class="ft-mgmt-team-period">
                    <span>Reporting period</span>
                    <select wire:model.live="teamPeriod" aria-label="Team performance reporting period">
                        <option value="this_week">This week</option>
                        <option value="this_month">This month</option>
                        <option value="last_30_days">Last 30 days</option>
                        <option value="custom">Custom range</option>
                    </select>
                </label>

                @if($teamPeriod === 'custom')
                    <div class="ft-mgmt-team-custom-range">
                        <label><span>From</span><input type="date" wire:model.live="teamCustomFrom" aria-label="Custom reporting period start"></label>
                        <label><span>To</span><input type="date" wire:model.live="teamCustomTo" aria-label="Custom reporting period end"></label>
                    </div>
                @endif

                <x-ui.search-select
                    class="ft-mgmt-remote-filter ft-mgmt-team-report-remote-filter"
                    label="Client"
                    property="clientFilter"
                    type="clients"
                    context="dashboard"
                    action="setReportFilter"
                    :value="$clientFilter"
                    placeholder="All clients"
                    :initial-options="$reportClientFilterOptions"
                    :menu-width="300"
                    :fixed-menu="true"
                    wire:key="team-report-client-filter-{{ $clientFilter ?: 'all' }}"
                />

                <x-ui.search-select
                    class="ft-mgmt-remote-filter ft-mgmt-team-report-remote-filter"
                    label="Team"
                    property="teamFilter"
                    type="departments"
                    context="dashboard"
                    action="setReportFilter"
                    :value="$teamFilter"
                    placeholder="All teams"
                    :initial-options="$reportTeamFilterOptions"
                    :menu-width="300"
                    :fixed-menu="true"
                    wire:key="team-report-team-filter-{{ $teamFilter ?: 'all' }}"
                />

                <label class="ft-mgmt-team-report-sort">
                    <span>Sort by</span>
                    <select wire:model.live="sort" aria-label="Sort team performance">
                        <option value="performance">Top performance</option>
                        <option value="workload">Workload</option>
                        <option value="name">Name</option>
                    </select>
                </label>

                <label class="ft-mgmt-team-report-search">
                    <span>Search</span>
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search employee" aria-label="Search employee performance">
                </label>

                @if($clientFilter !== '' || $teamFilter !== '' || $search !== '' || $sort !== 'performance')
                    <button type="button" class="ft-mgmt-btn ft-mgmt-team-report-clear" wire:click="clearFilters">Clear filters</button>
                @endif
            </div>
        </div>
    </section>

    <section class="ft-mgmt-panel ft-mgmt-team-panel ft-mgmt-team-report-results">
        <div class="ft-mgmt-panel-head ft-mgmt-team-report-results-head">
            <div>
                <h2>All team performance</h2>
                <p>{{ $resultCount }} {{ $resultCount === 1 ? 'user' : 'users' }} in the current report.</p>
            </div>
            <div class="ft-mgmt-team-report-period-summary">
                <strong>{{ $teamReportingPeriod['label'] ?? 'This week' }}</strong>
                <span>Live task totals · current assignee · cancelled/deleted tasks excluded</span>
            </div>
        </div>
        <div class="ft-mgmt-panel-body">
            <div class="ft-mgmt-team-grid">
                @forelse($assigneePerformance as $person)
                    <x-dashboard.team-performance-card :person="$person" wire:key="team-report-person-{{ $person->id }}" />
                @empty
                    <div class="ft-mgmt-empty ft-mgmt-team-report-empty">No team performance matches the selected filters and reporting period.</div>
                @endforelse
            </div>
        </div>

        @if($resultCount > 0)
            <div class="ft-mgmt-team-report-lazy" aria-label="Team performance lazy loading">
                <span class="ft-mgmt-priority-page-status">
                    Showing {{ $visibleCount }} of {{ $resultCount }} {{ $resultCount === 1 ? 'user' : 'users' }}
                </span>

                @if($hasMoreTeamPerformance)
                    <button
                        type="button"
                        class="ft-mgmt-team-report-load-more"
                        wire:click="loadMoreTeamPerformance"
                        wire:loading.attr="disabled"
                        wire:target="loadMoreTeamPerformance"
                    >
                        <span wire:loading.remove wire:target="loadMoreTeamPerformance">Load {{ $nextTeamBatchCount }} more</span>
                        <span wire:loading wire:target="loadMoreTeamPerformance">Loading…</span>
                    </button>
                @else
                    <span class="ft-mgmt-team-report-all-loaded">All users loaded</span>
                @endif
            </div>
        @endif
    </section>
</x-ui.management-theme>
