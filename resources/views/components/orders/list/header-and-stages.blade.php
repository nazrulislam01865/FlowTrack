@if(session('success'))
    <div class="ft-order-list-flash" role="status">{{ session('success') }}</div>
@endif

<header class="list-head">
    <div>
        <div class="breadcrumbs">{{ $pageBreadcrumbs }}</div>
        <h1>{{ $pageTitle }}</h1>
        <p class="sub">{{ $pageDescription }}</p>
    </div>
    @if($showPageActions)
        <div class="top-actions">
            @if(auth()->user()->canAccess('jobs.create'))
                <a class="btn" href="{{ route('orders.bulk-import') }}">⇧ Bulk order</a>
            @endif
            @if(auth()->user()->canModule('jobs', 'create'))
                <a class="btn primary" href="{{ route('jobs.index', ['create' => 1]) }}" wire:navigate>＋ Create order</a>
            @endif
        </div>
    @endif
</header>

<x-orders.workflow-stage-overview
    :stages="$stages"
    :selected-stage-id="$phaseFilter"
    mode="filter"
    :title="$workflowTitle"
    :description="$workflowDescription"
>
    <x-slot:summaryCards>
        <button
            type="button"
            class="ft-order-workflow-stage-card ft-order-workflow-metric-card {{ $metricFilter === 'createdToday' ? 'active' : '' }}"
            style="--stage:#2d72d9;--stage-text:#ffffff"
            wire:click="setMetricFilter('createdToday')"
            aria-pressed="{{ $metricFilter === 'createdToday' ? 'true' : 'false' }}"
        >
            @if($metricFilter === 'createdToday')
                <span class="ft-order-workflow-stage-selected" aria-hidden="true" title="Selected">✓</span>
            @endif
            <span class="ft-order-workflow-stage-kicker">Today</span>
            <b>Created Today</b>
            <span class="ft-order-workflow-stage-count">
                <em>Current filters</em>
                <strong>{{ number_format((int) ($metrics['createdToday'] ?? 0)) }}</strong>
            </span>
        </button>

        <button
            type="button"
            class="ft-order-workflow-stage-card ft-order-workflow-metric-card {{ $metricFilter === 'completed' ? 'active' : '' }}"
            style="--stage:#159a68;--stage-text:#ffffff"
            wire:click="setMetricFilter('completed')"
            aria-pressed="{{ $metricFilter === 'completed' ? 'true' : 'false' }}"
        >
            @if($metricFilter === 'completed')
                <span class="ft-order-workflow-stage-selected" aria-hidden="true" title="Selected">✓</span>
            @endif
            <span class="ft-order-workflow-stage-kicker">Status</span>
            <b>Completed</b>
            <span class="ft-order-workflow-stage-count">
                <em>Current filters</em>
                <strong>{{ number_format((int) ($metrics['completed'] ?? 0)) }}</strong>
            </span>
        </button>
    </x-slot:summaryCards>
</x-orders.workflow-stage-overview>
