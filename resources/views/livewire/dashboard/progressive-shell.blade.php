<x-ui.management-theme class="ft-mgmt-dashboard" data-dashboard-progressive="1">
    <div class="ft-mgmt-page-head">
        <div>
            <h1>Management Dashboard</h1>
            <p>Live operational overview across inquiries, orders, tasks, clients and product data.</p>
        </div>
        <div class="ft-mgmt-head-actions">
            @if($canCreateOrder)<a class="ft-mgmt-btn primary" href="{{ route('jobs.index', ['create' => 1]) }}" wire:navigate>＋ Create Order</a>@endif
            @if($canCreateInquiry)<a class="ft-mgmt-btn" href="{{ route('inquiries.index', ['create' => 1]) }}" wire:navigate>＋ Create Inquiry</a>@endif
            @if($canCreateClient)<a class="ft-mgmt-btn" href="{{ route('clients.index', ['create' => 1]) }}" wire:navigate>＋ Add Client</a>@endif
        </div>
    </div>

    <section class="ft-mgmt-control-bar" aria-label="Dashboard filters">
        <div class="ft-mgmt-range" wire:key="dashboard-range-control" wire:loading.class="is-loading" wire:target="setRange">
            <button type="button" wire:click="setRange(1)" wire:loading.attr="disabled" wire:target="setRange" aria-pressed="{{ $rangeDays === 1 ? 'true' : 'false' }}" class="{{ $rangeDays === 1 ? 'active' : '' }}">Today</button>
            <button type="button" wire:click="setRange(7)" wire:loading.attr="disabled" wire:target="setRange" aria-pressed="{{ $rangeDays === 7 ? 'true' : 'false' }}" class="{{ $rangeDays === 7 ? 'active' : '' }}">7 days</button>
            <button type="button" wire:click="setRange(30)" wire:loading.attr="disabled" wire:target="setRange" aria-pressed="{{ $rangeDays === 30 ? 'true' : 'false' }}" class="{{ $rangeDays === 30 ? 'active' : '' }}">30 days</button>
        </div>
        <x-ui.search-select
            class="ft-mgmt-remote-filter ft-mgmt-client-filter"
            label="Client"
            property="clientFilter"
            type="clients"
            context="dashboard"
            action="setDashboardFilter"
            :value="$clientFilter"
            placeholder="All clients"
            :initial-options="$dashboardClientFilterOptions"
            :menu-width="300"
            :fixed-menu="true"
            wire:key="dashboard-client-filter-{{ $clientFilter ?: 'all' }}"
        />
        <x-ui.search-select
            class="ft-mgmt-remote-filter ft-mgmt-team-filter"
            label="Team"
            property="teamFilter"
            type="departments"
            context="dashboard"
            action="setDashboardFilter"
            :value="$teamFilter"
            placeholder="All teams"
            :initial-options="$dashboardTeamFilterOptions"
            :menu-width="300"
            :fixed-menu="true"
            wire:key="dashboard-team-filter-{{ $teamFilter ?: 'all' }}"
        />
        <input class="ft-mgmt-search" wire:model.live.debounce.300ms="search" type="search" placeholder="Search orders, inquiries or tasks" aria-label="Search dashboard">
    </section>

    <livewire:dashboard.overview-panel
        :range-days="$rangeDays"
        :client-filter="$clientFilter"
        :team-filter="$teamFilter"
        :search="$search" :filter-generation="$filterGeneration"
        key="dashboard-progressive-overview"
    />

    <livewire:dashboard.priority-panel
        :range-days="$rangeDays" :client-filter="$clientFilter" :team-filter="$teamFilter" :search="$search" :filter-generation="$filterGeneration"
        key="dashboard-priority"
    />

    <section class="ft-mgmt-grid ft-mgmt-dashboard-pair-grid">
        <livewire:dashboard.attention-panel
            :range-days="$rangeDays" :client-filter="$clientFilter" :team-filter="$teamFilter" :search="$search" :filter-generation="$filterGeneration"
            key="dashboard-attention"
        />
        <livewire:dashboard.tagged-comments
            :range-days="$rangeDays" :client-filter="$clientFilter" :team-filter="$teamFilter" :search="$search" :filter-generation="$filterGeneration"
            key="dashboard-tagged-comments"
        />
    </section>

    <livewire:dashboard.distribution-panel
        :range-days="$rangeDays" :client-filter="$clientFilter" :team-filter="$teamFilter" :search="$search" :filter-generation="$filterGeneration"
        key="dashboard-distribution"
    />

    @if(auth()->user()->canAccess('reports.view'))
        <livewire:dashboard.team-panel
            :range-days="$rangeDays" :client-filter="$clientFilter" :team-filter="$teamFilter" :search="$search" :filter-generation="$filterGeneration"
            key="dashboard-team"
        />
    @endif

    <section class="ft-mgmt-grid ft-mgmt-dashboard-pair-grid">
        <livewire:dashboard.portfolio-panel
            :range-days="$rangeDays" :client-filter="$clientFilter" :team-filter="$teamFilter" :search="$search" :filter-generation="$filterGeneration"
            key="dashboard-portfolio"
        />
        <livewire:dashboard.catalogue-panel
            key="dashboard-catalogue"
        />
    </section>

    <livewire:dashboard.activity-panel
        :range-days="$rangeDays" :client-filter="$clientFilter" :team-filter="$teamFilter" :search="$search" :filter-generation="$filterGeneration"
        key="dashboard-activity"
    />
</x-ui.management-theme>
