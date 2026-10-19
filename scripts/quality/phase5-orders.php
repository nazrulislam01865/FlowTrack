#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = realpath(__DIR__ . '/../..');
if ($root === false) {
    fwrite(STDERR, "Unable to resolve project root.\n");
    exit(2);
}

$baselinePath = $root . '/quality/phase5-orders.json';
if (! is_file($baselinePath)) {
    fwrite(STDERR, "Missing quality/phase5-orders.json.\n");
    exit(2);
}

$baseline = json_decode((string) file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR);
$failures = [];

function p5Read(string $root, string $relative): string
{
    $path = $root . '/' . $relative;
    return is_file($path) ? (string) file_get_contents($path) : '';
}

function p5PhpMethods(string $source, string $visibility): array
{
    preg_match_all('/\\b' . preg_quote($visibility, '/') . '\\s+function\\s+([A-Za-z_][A-Za-z0-9_]*)\\s*\\(/', $source, $matches);
    return array_values(array_unique($matches[1] ?? []));
}

function p5Reconstruct(string $parent, array $partials): string
{
    foreach ($partials as $include => $partial) {
        $replacement = (string) file_get_contents($partial);
        $pattern = '/^[ \t]*' . preg_quote($include, '/') . '\R?/m';
        $parent = preg_replace($pattern, str_replace('\\', '\\\\', $replacement), $parent, 1, $count);
        if ($parent === null || $count !== 1) {
            throw new RuntimeException("Expected one include line for {$include}, found {$count}.");
        }
    }
    return $parent;
}


function p5NormalizePhase13Bindings(string $source): string
{
    return str_replace([
        'window.FlowTrack.ui.inlineEdit',
        'window.FlowTrack.ui.floatingActionMenu',
        'window.FlowTrack.ui.remoteFilter',
        'window.FlowTrack.ui.searchSelect',
        'window.FlowTrack.ui.multiSelect',
        'window.FlowTrack.ui.localFilter',
        'window.FlowTrack.ui.masterColor',
    ], [
        'window.FlowTrackInlineEdit',
        'window.FlowTrackFloatingActionMenu',
        'window.FlowTrackRemoteFilter',
        'window.FlowTrackSearchSelect',
        'window.FlowTrackMultiSelect',
        'window.FlowTrackLocalFilter',
        'window.FlowTrackMasterColor',
    ], $source);
}
$coordinatorRelative = 'app/Livewire/Jobs/Index.php';
$coordinator = p5Read($root, $coordinatorRelative);
$coordinatorLines = $coordinator === '' ? 0 : substr_count($coordinator, "\n") + 1;
$maxLines = (int) ($baseline['coordinator_max_lines'] ?? 500);
if ($coordinatorLines === 0) {
    $failures[] = "$coordinatorRelative is missing";
} elseif ($coordinatorLines > $maxLines) {
    $failures[] = "$coordinatorRelative grew to $coordinatorLines lines (Phase 5 maximum $maxLines)";
}

$concernSource = '';
foreach ($baseline['required_concerns'] ?? [] as $concern) {
    $relative = "app/Livewire/Jobs/Concerns/{$concern}.php";
    if (! is_file($root . '/' . $relative)) {
        $failures[] = "$relative is missing";
        continue;
    }
    if (! str_contains($coordinator, "use {$concern};")) {
        $failures[] = "$coordinatorRelative does not compose $concern";
    }
    $concernSource .= "\n" . p5Read($root, $relative);
}

$livewireSurface = $coordinator . $concernSource;
$publicMethods = p5PhpMethods($livewireSurface, 'public');
$missingMethods = array_values(array_diff($baseline['required_public_methods'] ?? [], $publicMethods));
if ($missingMethods !== []) {
    $failures[] = 'Livewire compatibility surface lost public methods: ' . implode(', ', $missingMethods);
}

$coordinatorPublic = p5PhpMethods($coordinator, 'public');
$allowedCoordinatorMethods = ['mount', 'refreshRealtime', 'render'];
$implementationMethods = array_values(array_diff($coordinatorPublic, $allowedCoordinatorMethods));
if ($implementationMethods !== []) {
    $failures[] = 'Jobs/Index.php contains workflow implementation methods instead of remaining a coordinator: ' . implode(', ', $implementationMethods);
}

$actionsSource = '';
$actionCount = 0;
foreach ($baseline['required_actions'] ?? [] as $action) {
    $relative = "app/Actions/Orders/{$action}.php";
    if (! is_file($root . '/' . $relative)) {
        $failures[] = "$relative is missing";
        continue;
    }
    $actionCount++;
    $source = p5Read($root, $relative);
    $actionsSource .= "\n" . $source;
    if (preg_match('/\\bLivewire\\\\|App\\\\Livewire\\\\/', $source)) {
        $failures[] = "$relative depends on Livewire; Order Actions must remain transport-independent";
    }
}

$queryCount = 0;
foreach ($baseline['required_queries'] ?? [] as $query) {
    $relative = "app/Queries/Orders/{$query}.php";
    if (! is_file($root . '/' . $relative)) {
        $failures[] = "$relative is missing";
        continue;
    }
    $queryCount++;
    $source = p5Read($root, $relative);
    if (preg_match('/\\bLivewire\\\\|App\\\\Livewire\\\\/', $source)) {
        $failures[] = "$relative depends on Livewire; Order Queries must remain transport-independent";
    }
}

$phase8 = is_file($root . '/quality/phase8-application.json')
    ? json_decode((string) file_get_contents($root . '/quality/phase8-application.json'), true)
    : null;
$phase10 = is_file($root . '/quality/phase10-document-security.json')
    ? json_decode((string) file_get_contents($root . '/quality/phase10-document-security.json'), true)
    : null;

foreach ($baseline['protected_file_hashes'] ?? [] as $relative => $expected) {
    if ($relative === 'app/Services/DocumentService.php' && is_array($phase10)) {
        $expected = (string) ($phase10['document_service_hash'] ?? $expected);
    }
    if ($relative === 'app/Services/OrderFinanceService.php' && is_array($phase10)) {
        $expected = (string) ($phase10['order_finance_service_hash'] ?? $expected);
    }
    if ($relative === 'app/Services/JobService.php' && is_array($phase8)) {
        $legacyPath = $root . '/app/Services/LegacyJobService.php';
        $legacyExpected = (string) ($phase8['legacy_job_service_hash'] ?? '');
        if (! is_file($legacyPath) || $legacyExpected === '' || ! hash_equals($legacyExpected, hash_file('sha256', $legacyPath))) {
            $failures[] = 'Phase 8 legacy JobService compatibility implementation changed unexpectedly';
        }
        continue;
    }
    $path = $root . '/' . $relative;
    if (! is_file($path)) {
        $failures[] = "$relative is missing";
        continue;
    }
    $actual = hash_file('sha256', $path);
    if (! hash_equals((string) $expected, $actual)) {
        $failures[] = "$relative changed during Phase 5 (protected compatibility/business boundary)";
    }
}

$viewSpecs = [
    'resources/views/components/jobs/create-products.blade.php' => [
        "@include('components.jobs.create.missing-product-supplier-modal')" => $root . '/resources/views/components/jobs/create/missing-product-supplier-modal.blade.php',
        "@include('components.jobs.create.product-modal')" => $root . '/resources/views/components/jobs/create/product-modal.blade.php',
    ],
    'resources/views/components/jobs/detail-documents.blade.php' => [
        "@include('components.jobs.documents.required-document-uploader')" => $root . '/resources/views/components/jobs/documents/required-document-uploader.blade.php',
        "@include('components.jobs.documents.document-library')" => $root . '/resources/views/components/jobs/documents/document-library.blade.php',
    ],
    'resources/views/components/jobs/task-detail.blade.php' => [
        "@include('components.jobs.task-detail.properties')" => $root . '/resources/views/components/jobs/task-detail/properties.blade.php',
        "@include('components.jobs.task-detail.description')" => $root . '/resources/views/components/jobs/task-detail/description.blade.php',
        "@include('components.jobs.task-detail.checklist')" => $root . '/resources/views/components/jobs/task-detail/checklist.blade.php',
        "@include('components.jobs.task-detail.attachments')" => $root . '/resources/views/components/jobs/task-detail/attachments.blade.php',
        "@include('components.jobs.task-detail.activity')" => $root . '/resources/views/components/jobs/task-detail/activity.blade.php',
        "@include('components.jobs.task-detail.sidebar')" => $root . '/resources/views/components/jobs/task-detail/sidebar.blade.php',
    ],
    'resources/views/components/orders/prototype-list.blade.php' => [
        "@include('components.orders.list.header-and-stages')" => $root . '/resources/views/components/orders/list/header-and-stages.blade.php',
        "@include('components.orders.list.filters')" => $root . '/resources/views/components/orders/list/filters.blade.php',
        "@include('components.orders.list.table')" => $root . '/resources/views/components/orders/list/table.blade.php',
        "@include('components.orders.list.pagination')" => $root . '/resources/views/components/orders/list/pagination.blade.php',
    ],
];

foreach ($viewSpecs as $relative => $partials) {
    $parentPath = $root . '/' . $relative;
    if (! is_file($parentPath)) {
        $failures[] = "$relative is missing";
        continue;
    }
    foreach ($partials as $partial) {
        if (! is_file($partial)) {
            $failures[] = str_replace($root . '/', '', $partial) . ' is missing';
        }
    }
    if ($failures !== [] && array_filter($partials, fn ($path) => ! is_file($path))) {
        continue;
    }
    try {
        $reconstructed = p5Reconstruct((string) file_get_contents($parentPath), $partials);
    } catch (RuntimeException $e) {
        $failures[] = "$relative reconstruction failed: {$e->getMessage()}";
        continue;
    }
    $actualHash = hash('sha256', p5NormalizePhase13Bindings($reconstructed));
    $expectedHash = (string) (($baseline['reconstructed_view_hashes'] ?? [])[$relative] ?? '');
    if ($expectedHash === '' || ! hash_equals($expectedHash, $actualHash)) {
        $failures[] = "$relative reconstructed markup differs from the pre-Phase-5 view";
    }
}

foreach ([
    "#[Url(as: 'open', history: true)]",
    "#[Url(as: 'task', history: true)]",
    "#[Url(as: 'comment', history: true)]",
] as $urlContract) {
    if (! str_contains($coordinator, $urlContract)) {
        $failures[] = "$coordinatorRelative lost deep-link contract $urlContract";
    }
}

printf("Phase 5 Orders decomposition gate\n");
printf("%-32s %8s\n", 'Metric', 'Current');
printf("%-32s %8d\n", 'Jobs/Index lines', $coordinatorLines);
printf("%-32s %8d\n", 'Livewire public methods', count($publicMethods));
printf("%-32s %8d\n", 'Order concerns', count($baseline['required_concerns'] ?? []));
printf("%-32s %8d\n", 'Order actions', $actionCount);
printf("%-32s %8d\n", 'Order queries', $queryCount);
printf("%-32s %8d\n", 'Exact reconstructed views', count($viewSpecs));

if ($failures !== []) {
    fwrite(STDERR, "\nPhase 5 Orders decomposition gate failed:\n - " . implode("\n - ", array_values(array_unique($failures))) . "\n");
    exit(1);
}

echo "\nPASS: Jobs/Index is a compatibility coordinator, the original Livewire method/deep-link surface is preserved, Order Actions/Queries are transport-independent, protected Order boundaries remain frozen except explicitly approved later-phase document-security changes, and extracted Blade views reconstruct exactly to their pre-Phase-5 markup.\n";
