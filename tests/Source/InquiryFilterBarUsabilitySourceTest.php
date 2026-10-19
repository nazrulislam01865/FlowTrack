<?php

$root = dirname(__DIR__, 2);
$viewPath = $root.'/resources/views/livewire/inquiries/sections/list.blade.php';
$cssPath = $root.'/resources/css/modules/inquiries/filters.css';

$view = file_get_contents($viewPath);
$css = file_get_contents($cssPath);

if ($view === false || $css === false) {
    fwrite(STDERR, "Unable to read Inquiry list source files.\n");
    exit(1);
}

$start = strpos($view, '<x-ui.filter-bar class="filters inquiry-filter-controls"');
$end = strpos($view, '</x-ui.filter-bar>', $start ?: 0);

if ($start === false || $end === false) {
    fwrite(STDERR, "Inquiry filter bar markup was not found.\n");
    exit(1);
}

$filterBar = substr($view, $start, $end - $start);

$failures = [];

if (str_contains($filterBar, '>Created today</x-ui.filter-chip>')) {
    $failures[] = 'Created today must not be duplicated in the filter bar.';
}

if (str_contains($filterBar, '>Completed</x-ui.filter-chip>')) {
    $failures[] = 'Completed must not be duplicated in the filter bar.';
}

foreach (['>All</x-ui.filter-chip>', 'Attention needed', 'pendingListStatus', 'pendingListClient', 'pendingHideCompleted', 'pendingDateFrom', 'pendingDateTo', 'applyFilters', 'clearFilters'] as $required) {
    if (! str_contains($filterBar, $required)) {
        $failures[] = "Missing required filter control: {$required}";
    }
}

if (! str_contains($filterBar, 'ft-inquiry-filter-grid')) {
    $failures[] = 'The filter controls must use the unified ft-inquiry-filter-grid layout.';
}

$quickGroupStart = strpos($css, '.ft-inquiry-prototype .inquiry-list-v2 .ft-inquiry-filter-group--quick {');
$quickGroupEnd = $quickGroupStart === false ? false : strpos($css, '}', $quickGroupStart);
$quickGroupCss = ($quickGroupStart === false || $quickGroupEnd === false)
    ? ''
    : substr($css, $quickGroupStart, $quickGroupEnd - $quickGroupStart);

foreach (['border:', 'background:', 'padding:', 'border-radius:'] as $forbiddenStyle) {
    if (str_contains($quickGroupCss, $forbiddenStyle)) {
        $failures[] = "Quick-filter group must not add an outer {$forbiddenStyle} wrapper style.";
    }
}

$quickChipStart = strpos($css, '.ft-inquiry-prototype .inquiry-list-v2 .ft-inquiry-filter-group--quick > .chip {');
$quickChipEnd = $quickChipStart === false ? false : strpos($css, '}', $quickChipStart);
$quickChipCss = ($quickChipStart === false || $quickChipEnd === false)
    ? ''
    : substr($css, $quickChipStart, $quickChipEnd - $quickChipStart);

foreach (['height: 44px;', 'min-height: 44px;', 'border-radius: 9px;'] as $requiredSizing) {
    if (! str_contains($quickChipCss, $requiredSizing)) {
        $failures[] = "Quick filters must match the standard control sizing: {$requiredSizing}";
    }
}

foreach ([
    '.ft-inquiry-filter-grid',
    'grid-template-columns:',
    '@media (max-width: 1180px)',
    '@media (max-width: 760px)',
] as $requiredCss) {
    if (! str_contains($css, $requiredCss)) {
        $failures[] = "Missing responsive filter CSS: {$requiredCss}";
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "Inquiry filter bar source regression checks passed.\n";
