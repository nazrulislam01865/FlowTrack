<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
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
]));

foreach ($attributes->all() as $__key => $__value) {
    if (in_array($__key, $__propNames)) {
        $$__key = $$__key ?? $__value;
    } else {
        $__newAttributes[$__key] = $__value;
    }
}

$attributes = new \Illuminate\View\ComponentAttributeBag($__newAttributes);

unset($__propNames);
unset($__newAttributes);

foreach (array_filter(([
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
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php
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
?>

<div class="ft-order-list-v5">
    <?php echo $__env->make('components.orders.list.header-and-stages', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    <?php echo $__env->make('components.orders.list.filters', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    <?php echo $__env->make('components.orders.list.table', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    <?php echo $__env->make('components.orders.list.pagination', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</div>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/laravel/Flowtrackfor/resources/views/components/orders/prototype-list.blade.php ENDPATH**/ ?>