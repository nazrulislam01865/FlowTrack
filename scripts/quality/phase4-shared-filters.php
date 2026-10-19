#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = realpath(__DIR__ . '/../..');
if ($root === false) {
    fwrite(STDERR, "Unable to resolve project root.\n");
    exit(2);
}

function p4Read(string $root, string $relative): string
{
    $path = $root . '/' . $relative;
    return is_file($path) ? (string) file_get_contents($path) : '';
}

function p4BladeFiles(string $root): array
{
    $base = $root . '/resources/views';
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }
    sort($files);
    return $files;
}

function p4CountUsage(array $files, string $component): int
{
    $count = 0;
    $needle = '<x-ui.' . $component;
    foreach ($files as $file) {
        $count += substr_count((string) file_get_contents($file), $needle);
    }
    return $count;
}

$phase15Final = is_file($root . '/quality/phase15-release-hardening.json');
$browserApiFile = $phase15Final ? 'resources/js/core/browser-api.js' : 'resources/js/compatibility/browser-bridge.js';

$requiredFiles = [
    'app/Support/Filters/FilterOptionPage.php',
    'app/Services/FilterOptionService.php',
    'app/Http/Controllers/FilterOptionController.php',
    'resources/views/components/ui/search-select.blade.php',
    'resources/views/components/ui/multi-select.blade.php',
    'resources/views/components/ui/search-input.blade.php',
    'resources/views/components/ui/filter-bar.blade.php',
    'resources/views/components/ui/filter-chip.blade.php',
    'resources/views/components/ui/filter-reset.blade.php',
    'resources/views/components/ui/date-range.blade.php',
    'resources/views/components/ui/server-error.blade.php',
    'resources/css/components/search-select.css',
    'resources/css/components/multi-select.css',
    'resources/css/components/filters.css',
    'resources/css/components/date-range.css',
    'resources/js/components/list-filters.js',
    $browserApiFile,
];

$failures = [];
foreach ($requiredFiles as $relative) {
    if (! is_file($root . '/' . $relative)) {
        $failures[] = "$relative is missing";
    }
}

$service = p4Read($root, 'app/Services/FilterOptionService.php');
$pageDto = p4Read($root, 'app/Support/Filters/FilterOptionPage.php');
$controller = p4Read($root, 'app/Http/Controllers/FilterOptionController.php');
$componentsCss = p4Read($root, 'resources/css/components.css');
$runtime = p4Read($root, 'resources/js/components/list-filters.js') . "\n" . p4Read($root, $browserApiFile);

$requiredSnippets = [
    'FilterOptionService.php' => [
        'public const MIN_SEARCH_LENGTH = 2;',
        'public const MAX_PER_PAGE = 20;',
        'public const MAX_SELECTED = 100;',
        'public function searchPage(',
        'if ($search !== \'\' && mb_strlen($search) < self::MIN_SEARCH_LENGTH)',
        'items: collect()',
        'selectedItems: $selectedItems',
        '$window = $this->window(',
        '$hasMore = $window->count() > $perPage;',
        'nextPage: $hasMore ? $page + 1 : null',
    ],
    'FilterOptionPage.php' => [
        "'items' =>",
        "'selected_items' =>",
        "'pagination' =>",
        "'has_more' =>",
        "'next_page' =>",
        "'min_length' =>",
    ],
    'FilterOptionController.php' => [
        "'per_page' => ['nullable', 'integer', 'min:1', 'max:'.FilterOptionService::MAX_PER_PAGE]",
        '$service->searchPage(',
        '$result->toArray()',
    ],
    'list-filters.js / browser bridge' => [
        'export const createSearchSelect',
        'export const createMultiSelect',
        'searchSelect: createSearchSelect',
        'multiSelect: createMultiSelect',
        'new AbortController()',
        'this.controller?.abort()',
        'visibleItems',
        'loadMore()',
    ],
];

foreach ($requiredSnippets as $name => $snippets) {
    $haystack = match ($name) {
        'FilterOptionService.php' => $service,
        'FilterOptionPage.php' => $pageDto,
        'FilterOptionController.php' => $controller,
        default => $runtime,
    };
    foreach ($snippets as $snippet) {
        if (! str_contains($haystack, $snippet)) {
            $failures[] = "$name missing contract snippet: $snippet";
        }
    }
}

foreach ([
    "@import './components/filters.css';",
    "@import './components/search-select.css';",
    "@import './components/multi-select.css';",
    "@import './components/date-range.css';",
] as $import) {
    if (! str_contains($componentsCss, $import)) {
        $failures[] = "components.css missing $import";
    }
}

$bladeFiles = p4BladeFiles($root);
$usage = [];
foreach (['search-select', 'multi-select', 'filter-bar', 'filter-chip', 'filter-reset', 'search-input', 'date-range'] as $component) {
    $usage[$component] = p4CountUsage($bladeFiles, $component);
}

$minimumUsage = [
    'search-select' => 20,
    'multi-select' => 2,
    'filter-bar' => 4,
    'filter-reset' => 3,
    'search-input' => 4,
    'date-range' => 2,
];
foreach ($minimumUsage as $component => $minimum) {
    if (($usage[$component] ?? 0) < $minimum) {
        $failures[] = "x-ui.$component usage fell to {$usage[$component]} (minimum $minimum); migrated screens may have reverted";
    }
}

// Ordinary feature views must not revive the superseded selector/filter APIs.
$deprecated = ['remote-filter', 'remote-select', 'select-filter', 'date-range-filter', 'list-search'];
$allowedDeprecated = [
    'resources/views/components/ui/shipping-address-editor.blade.php',
];
foreach ($bladeFiles as $file) {
    $relative = str_replace($root . '/', '', $file);
    $source = (string) file_get_contents($file);
    foreach ($deprecated as $component) {
        if (str_contains($source, '<x-ui.' . $component) && ! in_array($relative, $allowedDeprecated, true)) {
            $failures[] = "$relative uses deprecated x-ui.$component instead of the Phase 4 shared contract";
        }
    }
}

$featureContracts = [
    'resources/views/livewire/workflow-setup/form.blade.php' => ['<x-ui.multi-select', 'property="selectedClientIds"', 'type="clients"'],
    'resources/views/components/catalog/product-form.blade.php' => ['<x-ui.multi-select', 'property="productClientIds"', 'type="clients"'],
    'resources/views/livewire/user-editor/index.blade.php' => ['<x-ui.search-select', 'property="departmentId"', 'type="departments"'],
    'resources/views/livewire/administration/index.blade.php' => ['<x-ui.search-select', 'property="departmentId"', 'type="departments"'],
    'resources/views/livewire/inquiries/index.blade.php' => ['<x-ui.search-input', '<x-ui.filter-bar', '<x-ui.date-range', '<x-ui.filter-reset'],
    'resources/views/livewire/documents/index.blade.php' => ['<x-ui.filter-bar', 'type="clients"', 'type="users"', 'type="jobs"'],
    'resources/views/components/jobs/table.blade.php' => ['<x-ui.filter-bar', '<x-ui.search-select', '<x-ui.date-range', '<x-ui.filter-reset'],
];
foreach ($featureContracts as $relative => $snippets) {
    $source = p4Read($root, $relative);
    // Phase 6 decomposes the Inquiry screen into inherited-state Blade sections.
    // Treat the parent + sections as one feature contract so Phase 4 remains
    // enforced without requiring duplicated markup in the coordinator view.
    if ($relative === 'resources/views/livewire/inquiries/index.blade.php') {
        $sectionFiles = glob($root . '/resources/views/livewire/inquiries/sections/*.blade.php') ?: [];
        sort($sectionFiles);
        foreach ($sectionFiles as $sectionFile) {
            $source .= "\n" . (string) file_get_contents($sectionFile);
        }
    }
    foreach ($snippets as $snippet) {
        if (! str_contains($source, $snippet)) {
            $failures[] = "$relative missing migrated Phase 4 contract: $snippet";
        }
    }
}

$documents = p4Read($root, 'app/Livewire/Documents/Index.php');
foreach ([
    "->options(\$user, 'clients', 'documents'",
    "->options(\$user, 'users', 'documents'",
    "->options(\$user, 'jobs', 'documents'",
] as $snippet) {
    if (! str_contains($documents, $snippet)) {
        $failures[] = "Documents selector source missing bounded FilterOptionService call: $snippet";
    }
}


// Create Order must reuse the WorkflowTemplate availability scope exactly. Do not
// narrow it again to applies_to=orders: the scope intentionally allows the exact
// client's specific Inquiry workflow while excluding generic Inquiry workflows.
$jobsIndex = p4Read($root, 'app/Livewire/Jobs/Index.php');
$jobsConcernDir = $root . '/app/Livewire/Jobs/Concerns';
if (is_dir($jobsConcernDir)) {
    $concernFiles = glob($jobsConcernDir . '/*.php') ?: [];
    sort($concernFiles);
    foreach ($concernFiles as $concernFile) {
        $jobsIndex .= "\n" . (string) file_get_contents($concernFile);
    }
}
$workflowTemplate = p4Read($root, 'app/Models/WorkflowTemplate.php');
$productSelector = p4Read($root, 'resources/views/components/catalog/create-product-quantity.blade.php');
if (! str_contains($workflowTemplate, "scopeAvailableForOrderCreation")) {
    $failures[] = 'WorkflowTemplate is missing the Create Order availability scope';
}
if (! str_contains($workflowTemplate, "->where('applies_to', 'inquiries')") || ! str_contains($workflowTemplate, "->where('client_availability', 'specific')")) {
    $failures[] = 'Create Order availability scope no longer permits the exact client-specific Inquiry workflow';
}
foreach (['app/Livewire/Jobs/Index.php' => $jobsIndex, 'app/Services/FilterOptionService.php' => $service] as $relative => $source) {
    if (preg_match("/->where\('applies_to',\s*'orders'\)\s*->availableForOrderCreation\(/", $source)) {
        $failures[] = "$relative re-narrows availableForOrderCreation() to orders and breaks client-specific Inquiry workflows";
    }
}
if (! str_contains($productSelector, "\$showCreateProductSuggestion = \$productSearchValue !== '' && (int) \$productResultTotal === 0;")) {
    $failures[] = 'shared product selector must offer Create Product only after a non-empty zero-match search';
}
if (! str_contains($productSelector, '@if($showCreateProductSuggestion && $canCreateCatalogProduct)')) {
    $failures[] = 'shared product selector create action is not guarded by zero-match + permission state';
}

$componentCssFiles = [
    'resources/css/components/filters.css',
    'resources/css/components/search-select.css',
    'resources/css/components/multi-select.css',
    'resources/css/components/date-range.css',
];
foreach ($componentCssFiles as $relative) {
    $css = p4Read($root, $relative);
    if (preg_match('/#[0-9a-fA-F]{3,8}\b/', $css)) {
        $failures[] = "$relative contains a hard-coded hex color";
    }
    if (str_contains($css, '!important')) {
        $failures[] = "$relative contains !important";
    }
}

printf("Phase 4 shared forms/filter/search gate\n");
printf("%-24s %8s\n", 'Component', 'Usages');
foreach ($usage as $name => $count) {
    printf("%-24s %8d\n", $name, $count);
}
printf("%-24s %8d\n", 'max_remote_page', 20);
printf("%-24s %8d\n", 'min_search_length', 2);
printf("%-24s %8d\n", 'max_selected', 100);

if ($failures !== []) {
    fwrite(STDERR, "\nPhase 4 shared forms/filter/search gate failed:\n - " . implode("\n - ", array_values(array_unique($failures))) . "\n");
    exit(1);
}

echo "\nPASS: shared selectors are bounded/paged, migrated feature contracts remain centralized, and deprecated page-level selector APIs were not reintroduced.\n";
