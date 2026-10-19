@props([
    'jobs',
    'rows' => [],
    'stages' => collect(),
    'metrics' => [],
    'selectedStage' => null,
    'stageQuickFilters' => ['all' => 'All'],
    'searchFilter' => '',
    'clientFilter' => '',
    'ownerFilter' => '',
    'holdOnFilter' => false,
    'phaseFilter' => '',
    'dateFrom' => '',
    'dateTo' => '',
    'metricFilter' => '',
    'stageQuick' => 'all',
    'stageSupplier' => '',
    'stageAssignee' => '',
    'stageUrgency' => '',
    'stageCarrier' => '',
    'stageClient' => '',
    'clientFilterOptions' => collect(),
    'ownerFilterOptions' => collect(),
    'stageAssigneeOptions' => collect(),
    'stageClientFilterOptions' => collect(),
    'supplierFilterOptions' => collect(),
    'shipmentUrgencyOptions' => collect(),
    'dashboardScope' => false,
    'dashboardRangeDays' => 0,
    'dashboardTeamLabel' => '',
    'importFilterId' => 0,
    'importFilterLabel' => '',
    'pageBreadcrumbs' => 'Order / Orders',
    'pageTitle' => 'Orders',
    'pageDescription' => 'Manage active orders, see the exact workflow stage, and open the next required action.',
    'showPageActions' => true,
    'workflowTitle' => 'Orders by workflow stage',
    'workflowDescription' => 'Click a stage to filter the orders below on this page.',
    'inlineListActions' => true,
])
@php
    $sequence = (int) data_get($selectedStage, 'sequence', 0);
    $stageName = (string) data_get($selectedStage, 'name', '');
    $currentPage = $jobs->currentPage();
    $lastPage = $jobs->lastPage();
    $pageNumbers = collect(range(1, max(1, $lastPage)))
        ->filter(fn ($page) => $lastPage <= 5 || $page === 1 || $page === $lastPage || abs($page - $currentPage) <= 1)
        ->values();
    $formatDate = static fn ($date) => filled($date) ? \Carbon\CarbonImmutable::parse($date)->format('M j, Y') : '—';
    $money = static fn ($value) => '$'.number_format((float) $value, 2);
    $headers = match ($sequence) {
        1 => ['Order','Client','Product / supplier','Purchase order','Owner / required date','Next action'],
        2 => ['Order','Product / supplier','Artwork','Client / sample','Assignee / due','Next action'],
        3 => ['Order','Product / supplier','Quantity','Production','Issue','Owner / required date','Next action'],
        4 => ['Order','Product / supplier','Inspection','QC status','Issue','Assignee / due','Next action'],
        5 => ['Order','Client','Urgency','Label / carrier','Tracking','Owner / delivery','Next action'],
        6 => ['Order','Client','Invoice','Amount','Invoice status','Owner / due','Next action'],
        7 => ['Order','Client','Invoice','Paid / balance','Payment status','Owner / due','Next action'],
        default => ['Order','Client & product','Current stage','Status','Owner / delivery','Progress'],
    };
    $selectedStageFiltersActive = filled($stageSupplier) || filled($stageAssignee) || filled($stageUrgency) || filled($stageCarrier) || filled($stageClient) || $stageQuick !== 'all';
    // CHANGE 2026-08-24: prototype checkbox colors for the selected phase.
    $stageQuickMeta = \App\Services\OrderListPrototypeService::quickFilterMeta($sequence);
@endphp

<div class="ft-order-list-v5">
    @include('components.orders.list.header-and-stages')
    @include('components.orders.list.filters')
    @include('components.orders.list.table')
    @include('components.orders.list.pagination')
</div>
