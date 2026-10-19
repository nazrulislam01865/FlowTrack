#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = realpath(__DIR__ . '/../..');
if ($root === false) {
    fwrite(STDERR, "Unable to resolve project root.\n");
    exit(2);
}

$baselinePath = $root . '/quality/phase6-inquiries.json';
if (! is_file($baselinePath)) {
    fwrite(STDERR, "Missing quality/phase6-inquiries.json.\n");
    exit(2);
}

$baseline = json_decode((string) file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR);
$failures = [];

function p6Read(string $root, string $relative): string
{
    $path = $root . '/' . $relative;
    return is_file($path) ? (string) file_get_contents($path) : '';
}

function p6Methods(string $source): array
{
    preg_match_all('/^\s*(public|protected|private)\s+function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\((.*?)\)\s*(?::\s*([^\{\n]+))?/ms', $source, $matches, PREG_SET_ORDER);
    $methods = [];
    foreach ($matches as $match) {
        $methods[$match[2]] = [
            'visibility' => $match[1],
            'arguments' => preg_replace('/\s+/', ' ', trim($match[3])) ?? '',
            'return' => preg_replace('/\s+/', ' ', trim($match[4] ?? '')) ?? '',
        ];
    }
    return $methods;
}

function p6ReconstructInquiryView(string $root): string
{
    $parent = p6Read($root, 'resources/views/livewire/inquiries/index.blade.php');
    $partials = [
        "@include('livewire.inquiries.sections.list')" => $root . '/resources/views/livewire/inquiries/sections/list.blade.php',
        "@include('livewire.inquiries.sections.create')" => $root . '/resources/views/livewire/inquiries/sections/create.blade.php',
        "@include('livewire.inquiries.sections.detail')" => $root . '/resources/views/livewire/inquiries/sections/detail.blade.php',
    ];

    foreach ($partials as $include => $partial) {
        if (! is_file($partial)) {
            throw new RuntimeException(str_replace($root . '/', '', $partial) . ' is missing');
        }
        $pattern = '/^[ \t]*' . preg_quote($include, '/') . '\R?/m';
        $replacement = (string) file_get_contents($partial);
        $parent = preg_replace_callback($pattern, static fn (): string => $replacement, $parent, 1, $count);
        if ($parent === null || $count !== 1) {
            throw new RuntimeException("Expected one include for {$include}, found {$count}.");
        }
    }

    return $parent;
}

function p6MigrationTreeHash(string $root): string
{
    $files = glob($root . '/database/migrations/*.php') ?: [];
    sort($files);
    $context = hash_init('sha256');
    foreach ($files as $file) {
        hash_update($context, basename($file));
        hash_update($context, "\0");
        hash_update($context, (string) file_get_contents($file));
        hash_update($context, "\0");
    }
    return hash_final($context);
}


function p6NormalizePhase13Bindings(string $source): string
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
$coordinatorRelative = 'app/Livewire/Inquiries/Index.php';
$coordinator = p6Read($root, $coordinatorRelative);
$coordinatorLines = $coordinator === '' ? 0 : substr_count($coordinator, "\n") + 1;
$maxLines = (int) ($baseline['coordinator_max_lines'] ?? 350);
if ($coordinatorLines === 0) {
    $failures[] = "$coordinatorRelative is missing";
} elseif ($coordinatorLines > $maxLines) {
    $failures[] = "$coordinatorRelative grew to {$coordinatorLines} lines (Phase 6 maximum {$maxLines})";
}

$concernSource = '';
$concernCount = 0;
foreach ($baseline['required_concerns'] ?? [] as $concern) {
    $relative = "app/Livewire/Inquiries/Concerns/{$concern}.php";
    if (! is_file($root . '/' . $relative)) {
        $failures[] = "$relative is missing";
        continue;
    }
    $concernCount++;
    if (! str_contains($coordinator, "use {$concern};")) {
        $failures[] = "$coordinatorRelative does not compose {$concern}";
    }
    $concernSource .= "\n" . p6Read($root, $relative);
}

$livewireSource = $coordinator . $concernSource;
$actualMethods = p6Methods($livewireSource);
foreach ($baseline['required_method_signatures'] ?? [] as $name => $expected) {
    if (! isset($actualMethods[$name])) {
        $failures[] = "Inquiry Livewire compatibility surface lost method {$name}";
        continue;
    }
    if ($actualMethods[$name] !== $expected) {
        $failures[] = "Inquiry Livewire method signature changed: {$name}";
    }
}

$coordinatorMethods = p6Methods($coordinator);
$allowedCoordinatorMethods = ['mount', 'refreshRealtime', 'prepareForWorkspaceRefresh', 'render'];
$implementationMethods = array_values(array_diff(array_keys($coordinatorMethods), $allowedCoordinatorMethods));
if ($implementationMethods !== []) {
    $failures[] = 'Inquiries/Index.php contains workflow implementation methods instead of remaining a coordinator: ' . implode(', ', $implementationMethods);
}

if (str_contains($livewireSource, 'InquiryService')) {
    $failures[] = 'Inquiry Livewire directly references InquiryService; Phase 6 must use Actions/Queries as the boundary';
}

$inquiryViews = '';
foreach ([
    'resources/views/livewire/inquiries',
    'resources/views/components/inquiries',
] as $relativeDir) {
    $dir = $root . '/' . $relativeDir;
    if (! is_dir($dir)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $inquiryViews .= "\n" . (string) file_get_contents($file->getPathname());
        }
    }
}
if (str_contains($inquiryViews, 'InquiryService')) {
    $failures[] = 'Inquiry Blade views directly resolve/reference InquiryService';
}

// Phase 6 requires user-initiated writes to cross an Action boundary.
if (preg_match('/::create\s*\(|->(?:create|update|delete|save)\s*\(|DB::transaction\s*\(/', $livewireSource)) {
    $failures[] = 'Inquiry Livewire contains a direct persistence write instead of an Action boundary';
}

$actionCount = 0;
foreach ($baseline['required_actions'] ?? [] as $action) {
    $relative = "app/Actions/Inquiries/{$action}.php";
    if (! is_file($root . '/' . $relative)) {
        $failures[] = "$relative is missing";
        continue;
    }
    $actionCount++;
    $source = p6Read($root, $relative);
    if (preg_match('/\bLivewire\\\\|App\\\\Livewire\\\\/', $source)) {
        $failures[] = "$relative depends on Livewire; Inquiry Actions must remain transport-independent";
    }
    if (in_array($action, $baseline['inquiry_service_actions'] ?? [], true)
        && ! str_contains($source, 'InquiryService $inquiries')
        && ! str_contains($source, 'App\\Services\\Inquiries\\')) {
        $failures[] = "$relative no longer delegates to an Inquiry application/domain service boundary";
    }
}

$queryCount = 0;
foreach ($baseline['required_queries'] ?? [] as $query) {
    $relative = "app/Queries/Inquiries/{$query}.php";
    if (! is_file($root . '/' . $relative)) {
        $failures[] = "$relative is missing";
        continue;
    }
    $queryCount++;
    $source = p6Read($root, $relative);
    if (preg_match('/\bLivewire\\\\|App\\\\Livewire\\\\/', $source)) {
        $failures[] = "$relative depends on Livewire; Inquiry Queries must remain transport-independent";
    }
}

$phase8 = is_file($root . '/quality/phase8-application.json')
    ? json_decode((string) file_get_contents($root . '/quality/phase8-application.json'), true)
    : null;
$phase10 = is_file($root . '/quality/phase10-document-security.json')
    ? json_decode((string) file_get_contents($root . '/quality/phase10-document-security.json'), true)
    : null;

foreach ($baseline['protected_file_hashes'] ?? [] as $relative => $expected) {
    if ($relative === 'app/Services/InquiryService.php' && is_array($phase8)) {
        $legacyPath = $root . '/app/Services/LegacyInquiryService.php';
        $legacyExpected = is_array($phase10)
            ? (string) ($phase10['legacy_inquiry_service_hash'] ?? ($phase8['legacy_inquiry_service_hash'] ?? ''))
            : (string) ($phase8['legacy_inquiry_service_hash'] ?? '');
        if (! is_file($legacyPath) || $legacyExpected === '' || ! hash_equals($legacyExpected, hash_file('sha256', $legacyPath))) {
            $failures[] = 'Phase 8 legacy InquiryService compatibility implementation changed unexpectedly';
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
        $failures[] = "$relative changed during Phase 6 (protected compatibility/business boundary)";
    }
}

$migrationSnapshotPath = $root . '/quality/pre-phase11-migration-hashes.json';
if (is_file($migrationSnapshotPath)) {
    $migrationSnapshot = json_decode((string) file_get_contents($migrationSnapshotPath), true, 512, JSON_THROW_ON_ERROR);
    foreach (($migrationSnapshot['files'] ?? []) as $filename => $hash) {
        $path = $root . '/database/migrations/' . $filename;
        if (! is_file($path) || ! hash_equals((string) $hash, hash_file('sha256', $path))) {
            $failures[] = 'Pre-Phase-11 migration changed: ' . $filename;
        }
    }
} else {
    $expectedMigrationHash = (string) ($baseline['migration_tree_hash'] ?? '');
    if ($expectedMigrationHash !== '' && ! hash_equals($expectedMigrationHash, p6MigrationTreeHash($root))) {
        $failures[] = 'database/migrations changed during Phase 6; this phase does not require schema changes';
    }
}

try {
    $reconstructed = p6ReconstructInquiryView($root);
    $actualHash = hash('sha256', p6NormalizePhase13Bindings($reconstructed));
    if (! hash_equals((string) ($baseline['reconstructed_view_hash'] ?? ''), $actualHash)) {
        $failures[] = 'Inquiry parent/section reconstructed markup changed after the approved Phase 6 split';
    }
} catch (RuntimeException $e) {
    $failures[] = 'Inquiry view reconstruction failed: ' . $e->getMessage();
}

foreach ([
    'resources/views/livewire/inquiries/_taskflow.blade.php' => 'taskflow_view_hash',
    'resources/views/livewire/inquiries/_attachments.blade.php' => 'attachments_view_hash',
    'resources/views/livewire/inquiries/_activity.blade.php' => 'activity_view_hash',
] as $relative => $key) {
    $path = $root . '/' . $relative;
    if (! is_file($path)) {
        $failures[] = "$relative is missing";
        continue;
    }
    if (! hash_equals((string) ($baseline[$key] ?? ''), hash('sha256', p6NormalizePhase13Bindings((string) file_get_contents($path))))) {
        $failures[] = "$relative changed after the approved Phase 6 compatibility extraction";
    }
}

foreach ([
    "request()->boolean('create')",
    "request()->integer('open')",
    "request()->integer('task')",
    "#[\\Livewire\\Attributes\\Url(as: 'metric', history: true, except: '')]",
] as $deepLinkContract) {
    if (! str_contains($coordinator, $deepLinkContract)) {
        $failures[] = "$coordinatorRelative lost route/deep-link contract: {$deepLinkContract}";
    }
}

$permissionTest = p6Read($root, 'tests/Feature/Phase6InquiryPermissionBoundaryTest.php');
foreach ([
    'assertTrue($access->canEditInquiryTask($creator, $task))',
    'assertFalse($access->canEditInquiryTask($outsider, $task))',
    "assertTrue(\$access->can(\$administrator, 'jobs', 'create'))",
    "assertFalse(\$access->can(\$regularUser, 'jobs', 'create'))",
    "foreach (['create', 'link', 'delete'] as \$action)",
] as $snippet) {
    if (! str_contains($permissionTest, $snippet)) {
        $failures[] = "Phase 6 permission boundary test missing: {$snippet}";
    }
}

printf("Phase 6 Inquiries decomposition gate\n");
printf("%-34s %8s\n", 'Metric', 'Current');
printf("%-34s %8d\n", 'Inquiries/Index lines', $coordinatorLines);
printf("%-34s %8d\n", 'Livewire methods preserved', count($actualMethods));
printf("%-34s %8d\n", 'Inquiry concerns', $concernCount);
printf("%-34s %8d\n", 'Inquiry actions', $actionCount);
printf("%-34s %8d\n", 'Inquiry queries', $queryCount);
printf("%-34s %8s\n", 'Direct InquiryService in UI', str_contains($livewireSource . $inquiryViews, 'InquiryService') ? 'YES' : '0');

if ($failures !== []) {
    fwrite(STDERR, "\nPhase 6 Inquiries decomposition gate failed:\n - " . implode("\n - ", array_values(array_unique($failures))) . "\n");
    exit(1);
}

echo "\nPASS: Inquiries/Index is a compatibility coordinator, all original method signatures are preserved, Inquiry UI is separated into focused concerns/sections, writes and reads cross Action/Query boundaries, Inquiry compatibility behavior/routes and all pre-Phase-11 migrations remain protected, with only the approved Phase-10 storage hardening accepted, and permission contracts have allowed/denied coverage.\n";
