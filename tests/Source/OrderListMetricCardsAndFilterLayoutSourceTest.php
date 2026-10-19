<?php

$root = dirname(__DIR__, 2);
$headerPath = $root.'/resources/views/components/orders/list/header-and-stages.blade.php';
$filtersPath = $root.'/resources/views/components/orders/list/filters.blade.php';
$stageViewPath = $root.'/resources/views/components/orders/workflow-stage-overview.blade.php';
$stageCssPath = $root.'/resources/css/components/order-workflow-stage-overview.css';
$listCssPath = $root.'/resources/css/modules/orders/list.css';

$header = file_get_contents($headerPath);
$filters = file_get_contents($filtersPath);
$stageView = file_get_contents($stageViewPath);
$stageCss = file_get_contents($stageCssPath);
$listCss = file_get_contents($listCssPath);

if ($header === false || $filters === false || $stageView === false || $stageCss === false || $listCss === false) {
    fwrite(STDERR, "Unable to read Order list source files.\n");
    exit(1);
}

$failures = [];

foreach ([
    "setMetricFilter('createdToday')",
    "setMetricFilter('completed')",
    "\$metrics['createdToday'] ?? 0",
    "\$metrics['completed'] ?? 0",
    'ft-order-workflow-metric-card',
] as $required) {
    if (! str_contains($header, $required)) {
        $failures[] = "Missing Order workflow metric-card source: {$required}";
    }
}

if (str_contains($header, 'ft-order-completed-summary')) {
    $failures[] = 'The old standalone Completed summary card must be removed.';
}

if (! str_contains($filters, 'ft-order-filter-main-row')) {
    $failures[] = 'Order filters must use one main desktop filter row.';
}

if (str_contains($filters, 'ft-order-filter-primary-row') || str_contains($filters, 'ft-order-filter-secondary-row')) {
    $failures[] = 'Order filters must not be split into primary and secondary rows.';
}

foreach ([
    '>Created today</button>',
    '>Completed</button>',
] as $forbidden) {
    if (str_contains($filters, $forbidden)) {
        $failures[] = "Duplicate metric filter must be removed from Order toolbar: {$forbidden}";
    }
}

foreach ([
    '>All</button>',
    '<span>Hold on</span>',
    'All stages',
    'ft-order-v5-client-filter',
    'ft-order-v5-owner-filter',
    'ft-order-list-date-range',
    'ft-order-filter-reset',
] as $required) {
    if (! str_contains($filters, $required)) {
        $failures[] = "Order filter row is missing: {$required}";
    }
}

if (! str_contains($stageView, 'ft-order-workflow-metric-strip')) {
    $failures[] = 'Workflow-stage component must host the metric strip inside the same responsive container.';
}

foreach ([
    '.ft-order-workflow-metric-strip',
    '.ft-order-workflow-metric-card',
] as $requiredCss) {
    if (! str_contains($stageCss, $requiredCss)) {
        $failures[] = "Missing workflow metric-card CSS: {$requiredCss}";
    }
}

foreach ([
    '.ft-order-filter-main-row',
    '@media (max-width: 1180px)',
    '@media (max-width: 980px)',
    '@media (max-width: 620px)',
] as $requiredCss) {
    if (! str_contains($listCss, $requiredCss)) {
        $failures[] = "Missing responsive Order filter CSS: {$requiredCss}";
    }
}

foreach ([
    '.ft-order-filter-primary-row',
    '.ft-order-filter-secondary-row',
] as $forbiddenCss) {
    if (str_contains($listCss, $forbiddenCss)) {
        $failures[] = "Legacy two-row Order filter CSS must be removed: {$forbiddenCss}";
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "Order metric cards and single-row filter layout source regression checks passed.\n";
