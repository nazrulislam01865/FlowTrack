#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = realpath(__DIR__ . '/../..');
if ($root === false) {
    fwrite(STDERR, "Unable to resolve project root.\n");
    exit(2);
}

function uiRead(string $root, string $relative): string
{
    $path = $root . '/' . $relative;
    if (! is_file($path)) {
        return '';
    }

    return (string) file_get_contents($path);
}

function uiStripComments(string $css): string
{
    return preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;
}

function uiStaticColorCount(string $css): int
{
    $css = uiStripComments($css);
    $patterns = [
        '/#[0-9a-fA-F]{3,8}\b/',
        '/\brgba?\s*\(/i',
        '/\bhsla?\s*\(/i',
        '/\boklch\s*\(/i',
        '/\boklab\s*\(/i',
    ];

    $count = 0;
    foreach ($patterns as $pattern) {
        $matches = preg_match_all($pattern, $css, $unused);
        $count += $matches === false ? 0 : $matches;
    }

    return $count;
}

function uiBladeFiles(string $directory): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

$componentCss = [
    'resources/css/components/headers.css',
    'resources/css/components/buttons.css',
    'resources/css/components/badges.css',
    'resources/css/components/forms.css',
    'resources/css/components/filters.css',
    'resources/css/components/search-select.css',
    'resources/css/components/multi-select.css',
    'resources/css/components/date-range.css',
    'resources/css/components/dropdowns.css',
    'resources/css/components/cards.css',
    'resources/css/components/tables.css',
    'resources/css/components/tabs.css',
    'resources/css/components/modals.css',
    'resources/css/components/tooltips.css',
    'resources/css/components/pagination.css',
    'resources/css/components/loading.css',
    'resources/css/components/empty-state.css',
    'resources/css/components/validation.css',
];

$componentViews = [
    'resources/views/components/ui/button.blade.php',
    'resources/views/components/ui/icon-button.blade.php',
    'resources/views/components/ui/badge.blade.php',
    'resources/views/components/ui/status-badge.blade.php',
    'resources/views/components/ui/page-header.blade.php',
    'resources/views/components/ui/section-header.blade.php',
    'resources/views/components/ui/card.blade.php',
    'resources/views/components/ui/field.blade.php',
    'resources/views/components/ui/input.blade.php',
    'resources/views/components/ui/textarea.blade.php',
    'resources/views/components/ui/select.blade.php',
    'resources/views/components/ui/remote-select.blade.php',
    'resources/views/components/ui/date-input.blade.php',
    'resources/views/components/ui/modal.blade.php',
    'resources/views/components/ui/table.blade.php',
    'resources/views/components/ui/tabs.blade.php',
    'resources/views/components/ui/tab.blade.php',
    'resources/views/components/ui/pagination.blade.php',
    'resources/views/components/ui/loading.blade.php',
    'resources/views/components/ui/empty-state.blade.php',
    'resources/views/components/ui/validation-message.blade.php',
    'resources/views/components/ui/tooltip.blade.php',
];

$failures = [];
$rows = [];

foreach ([...$componentCss, ...$componentViews] as $relative) {
    $exists = is_file($root . '/' . $relative);
    $rows[] = [$relative, $exists ? 'present' : 'missing', $exists ? 'PASS' : 'FAIL'];
    if (! $exists) {
        $failures[] = "$relative is required by the Phase 2 component contract";
    }
}

$componentsRoot = uiStripComments(uiRead($root, 'resources/css/components.css'));
preg_match_all('/@import\s+[\'\"]([^\'\"]+)[\'\"]\s*;/', $componentsRoot, $componentImports);
$expectedComponentImports = array_map(
    static fn (string $relative): string => './components/' . basename($relative),
    $componentCss
);
$actualComponentImports = $componentImports[1] ?? [];
$componentRootRemainder = preg_replace('/@import\s+[\'\"][^\'\"]+[\'\"]\s*;/', '', $componentsRoot) ?? '';

if ($actualComponentImports !== $expectedComponentImports) {
    $failures[] = 'resources/css/components.css import order differs from the approved Phase 2 contract';
}
if (trim($componentRootRemainder) !== '') {
    $failures[] = 'resources/css/components.css must remain composition-only';
}
$rows[] = ['components.css import contract', $actualComponentImports === $expectedComponentImports ? 'approved' : 'changed', $actualComponentImports === $expectedComponentImports ? 'PASS' : 'FAIL'];
$rows[] = ['components.css composition-only', trim($componentRootRemainder) === '' ? 'yes' : 'no', trim($componentRootRemainder) === '' ? 'PASS' : 'FAIL'];

foreach ($componentCss as $relative) {
    $css = uiRead($root, $relative);
    $withoutComments = uiStripComments($css);
    $colorCount = uiStaticColorCount($css);
    $importantCount = substr_count($withoutComments, '!important');
    preg_match_all('/\.([A-Za-z_-][A-Za-z0-9_-]*)/', $withoutComments, $classMatches);
    $invalidClasses = array_values(array_unique(array_filter(
        $classMatches[1] ?? [],
        static fn (string $class): bool => ! str_starts_with($class, 'ft-') && ! str_starts_with($class, 'u-')
    )));

    $rows[] = ["$relative static colors", (string) $colorCount, $colorCount === 0 ? 'PASS' : 'FAIL'];
    $rows[] = ["$relative !important", (string) $importantCount, $importantCount === 0 ? 'PASS' : 'FAIL'];
    $rows[] = ["$relative namespace", $invalidClasses === [] ? 'approved' : implode(',', $invalidClasses), $invalidClasses === [] ? 'PASS' : 'FAIL'];

    if ($colorCount > 0) {
        $failures[] = "$relative contains static color literals; shared component CSS must consume tokens";
    }
    if ($importantCount > 0) {
        $failures[] = "$relative contains !important; official components must not depend on specificity escalation";
    }
    if ($invalidClasses !== []) {
        $failures[] = "$relative contains non-namespaced classes: " . implode(', ', $invalidClasses);
    }
}

foreach ($componentViews as $relative) {
    $blade = uiRead($root, $relative);
    $styleBlocks = preg_match_all('/<style\b/i', $blade, $unusedStyleBlocks) ?: 0;
    $phpBlocks = preg_match_all('/@php\b/', $blade, $unusedPhpBlocks) ?: 0;
    $hexColors = preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $blade, $unusedHex) ?: 0;

    $rows[] = ["$relative style blocks", (string) $styleBlocks, $styleBlocks === 0 ? 'PASS' : 'FAIL'];
    $rows[] = ["$relative @php blocks", (string) $phpBlocks, $phpBlocks === 0 ? 'PASS' : 'FAIL'];
    $rows[] = ["$relative hard-coded colors", (string) $hexColors, $hexColors === 0 ? 'PASS' : 'FAIL'];

    if ($styleBlocks > 0) {
        $failures[] = "$relative contains a style block; component appearance belongs in resources/css/components";
    }
    if ($phpBlocks > 0) {
        $failures[] = "$relative contains @php; official Phase 2 component views must stay presentation-focused";
    }
    if ($hexColors > 0) {
        $failures[] = "$relative contains hard-coded colors";
    }
}

$markerViews = [
    'button.blade.php' => 'data-ft-ui-component="button"',
    'icon-button.blade.php' => 'data-ft-ui-component="icon-button"',
    'badge.blade.php' => 'data-ft-ui-component="badge"',
    'page-header.blade.php' => 'data-ft-ui-component="page-header"',
    'section-header.blade.php' => 'data-ft-ui-component="section-header"',
    'card.blade.php' => 'data-ft-ui-component="card"',
    'field.blade.php' => 'data-ft-ui-component="field"',
    'input.blade.php' => 'data-ft-ui-component="field"',
    'textarea.blade.php' => 'data-ft-ui-component="field"',
    'select.blade.php' => 'data-ft-ui-component="field"',
    'remote-select.blade.php' => 'data-ft-ui-component="remote-select"',
    'date-input.blade.php' => 'data-ft-ui-component="field"',
    'modal.blade.php' => 'data-ft-ui-component="modal"',
    'table.blade.php' => 'data-ft-ui-component="table"',
    'tabs.blade.php' => 'data-ft-ui-component="tabs"',
    'tab.blade.php' => 'data-ft-ui-component="tab"',
    'pagination.blade.php' => 'data-ft-ui-component="pagination"',
    'loading.blade.php' => 'data-ft-ui-component="loading"',
    'empty-state.blade.php' => 'data-ft-ui-component="empty-state"',
    'validation-message.blade.php' => 'data-ft-ui-component="validation-message"',
    'tooltip.blade.php' => 'data-ft-ui-component="tooltip"',
];

foreach ($markerViews as $file => $marker) {
    $relative = 'resources/views/components/ui/' . $file;
    $hasMarker = str_contains(uiRead($root, $relative), $marker);
    $rows[] = ["$file compatibility marker", $hasMarker ? 'present' : 'missing', $hasMarker ? 'PASS' : 'FAIL'];
    if (! $hasMarker) {
        $failures[] = "$relative must expose $marker so Phase 2 styles cannot leak onto legacy class collisions";
    }
}

$baselinePath = $root . '/quality/ui-component-baseline.json';
if (! is_file($baselinePath)) {
    $failures[] = 'quality/ui-component-baseline.json is missing';
    $baseline = ['direct_root_class_occurrences' => []];
} else {
    $baseline = json_decode((string) file_get_contents($baselinePath), true, flags: JSON_THROW_ON_ERROR);
}

$directRoots = array_keys($baseline['direct_root_class_occurrences'] ?? []);
$currentCounts = array_fill_keys($directRoots, 0);
$viewRoot = $root . '/resources/views';
$uiComponentRoot = str_replace('\\', '/', $root . '/resources/views/components/ui/');

foreach (uiBladeFiles($viewRoot) as $file) {
    $normalized = str_replace('\\', '/', $file);
    if (str_starts_with($normalized, $uiComponentRoot)) {
        continue;
    }

    $blade = (string) file_get_contents($file);
    foreach ($directRoots as $class) {
        $pattern = '/class\s*=\s*[\"\'][^\"\']*(?<![A-Za-z0-9_-])' . preg_quote($class, '/') . '(?![A-Za-z0-9_-])[^\"\']*[\"\']/';
        $count = preg_match_all($pattern, $blade, $unused);
        $currentCounts[$class] += $count === false ? 0 : $count;
    }
}

foreach (($baseline['direct_root_class_occurrences'] ?? []) as $class => $limit) {
    $value = $currentCounts[$class] ?? 0;
    $status = $value <= (int) $limit ? 'PASS' : 'FAIL';
    $rows[] = ["direct page .$class markup", "$value <= $limit", $status];
    if ($status === 'FAIL') {
        $failures[] = "direct page .$class markup increased to $value (Phase 1 ceiling $limit); use the x-ui component";
    }
}

$referenceView = $root . '/resources/views/dev/ui-kit.blade.php';
$referenceRoute = uiRead($root, 'routes/web.php');
$referenceOk = is_file($referenceView)
    && str_contains($referenceRoute, "Route::view('/_dev/ui-kit', 'dev.ui-kit')")
    && str_contains($referenceRoute, "app()->environment('local', 'testing')");
$rows[] = ['developer UI reference', $referenceOk ? 'local/testing only' : 'missing or unsafe', $referenceOk ? 'PASS' : 'FAIL'];
if (! $referenceOk) {
    $failures[] = 'the developer UI reference must exist and be registered only in local/testing environments';
}

printf("Phase 2 UI component library governance\n%-76s %-24s %s\n", 'Check', 'Value', 'Status');
foreach ($rows as [$name, $value, $status]) {
    printf("%-76s %-24s %s\n", $name, $value, $status);
}

if ($failures !== []) {
    fwrite(STDERR, "\nUI component library gate failed:\n - " . implode("\n - ", array_values(array_unique($failures))) . "\n");
    exit(1);
}

echo "\nPASS: official Phase 2 components are centralized, token-driven, namespaced and compatibility-safe.\n";
