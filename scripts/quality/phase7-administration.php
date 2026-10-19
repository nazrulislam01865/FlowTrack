#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = realpath(__DIR__ . '/../..');
if ($root === false) {
    fwrite(STDERR, "Unable to resolve project root.\n");
    exit(2);
}

$baselinePath = $root . '/quality/phase7-administration.json';
if (! is_file($baselinePath)) {
    fwrite(STDERR, "Missing quality/phase7-administration.json.\n");
    exit(2);
}

$baseline = json_decode((string) file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR);
$failures = [];

function p7Read(string $root, string $relative): string
{
    $path = $root . '/' . $relative;
    return is_file($path) ? (string) file_get_contents($path) : '';
}

function p7Methods(string $source): array
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

function p7TreeHash(string $root, string $relativeDir, string $suffix): string
{
    $dir = $root . '/' . $relativeDir;
    $files = [];
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), $suffix)) {
                $files[] = $file->getPathname();
            }
        }
    }
    sort($files);
    $context = hash_init('sha256');
    foreach ($files as $file) {
        hash_update($context, str_replace($root . '/', '', $file));
        hash_update($context, "\0");
        hash_update($context, (string) file_get_contents($file));
        hash_update($context, "\0");
    }
    return hash_final($context);
}

function p7AssertMethodSurface(array &$failures, string $label, array $expected, array $actual): void
{
    foreach ($expected as $name => $signature) {
        if (! isset($actual[$name])) {
            $failures[] = "$label lost method {$name}";
            continue;
        }
        if ($actual[$name] !== $signature) {
            $failures[] = "$label method signature changed: {$name}";
        }
    }
}

$masterCoordinator = p7Read($root, 'app/Livewire/MasterData/Index.php');
$clientCoordinator = p7Read($root, 'app/Livewire/Clients/Index.php');
$masterLines = $masterCoordinator === '' ? 0 : substr_count($masterCoordinator, "\n") + 1;
$clientLines = $clientCoordinator === '' ? 0 : substr_count($clientCoordinator, "\n") + 1;

if ($masterLines === 0 || $masterLines > (int) ($baseline['master_data_coordinator_max_lines'] ?? 400)) {
    $failures[] = "MasterData/Index.php must remain a small Phase 7 coordinator; current lines: {$masterLines}";
}
if ($clientLines === 0 || $clientLines > (int) ($baseline['clients_coordinator_max_lines'] ?? 260)) {
    $failures[] = "Clients/Index.php must remain a small Phase 7 coordinator; current lines: {$clientLines}";
}

$masterConcernSource = '';
$masterConcernFiles = glob($root . '/app/Livewire/MasterData/Concerns/*.php') ?: [];
sort($masterConcernFiles);
foreach ($masterConcernFiles as $file) $masterConcernSource .= "\n" . (string) file_get_contents($file);
$clientConcernSource = '';
$clientConcernFiles = glob($root . '/app/Livewire/Clients/Concerns/*.php') ?: [];
sort($clientConcernFiles);
foreach ($clientConcernFiles as $file) $clientConcernSource .= "\n" . (string) file_get_contents($file);

$masterSurface = $masterCoordinator . $masterConcernSource;
$clientSurface = $clientCoordinator . $clientConcernSource;
p7AssertMethodSurface($failures, 'Master Data Livewire', $baseline['master_data_methods'] ?? [], p7Methods($masterSurface));
p7AssertMethodSurface($failures, 'Client Livewire', $baseline['client_methods'] ?? [], p7Methods($clientSurface));

foreach ($baseline['required_master_concerns'] ?? [] as $concern) {
    $relative = "app/Livewire/MasterData/Concerns/{$concern}.php";
    if (! is_file($root . '/' . $relative)) {
        $failures[] = "$relative is missing";
        continue;
    }
    if (! str_contains($masterSurface, "use {$concern};") && ! str_contains($masterSurface, "trait {$concern}")) {
        $failures[] = "Master Data decomposition no longer composes {$concern}";
    }
}
foreach ($baseline['required_client_concerns'] ?? [] as $concern) {
    $relative = "app/Livewire/Clients/Concerns/{$concern}.php";
    if (! is_file($root . '/' . $relative)) {
        $failures[] = "$relative is missing";
        continue;
    }
    if (! str_contains($clientSurface, "use {$concern};") && ! str_contains($clientSurface, "trait {$concern}")) {
        $failures[] = "Client decomposition no longer composes {$concern}";
    }
}

foreach ([
    ['key' => 'required_master_actions', 'dir' => 'app/Actions/MasterData'],
    ['key' => 'required_client_actions', 'dir' => 'app/Actions/Clients'],
    ['key' => 'required_setup_actions', 'dir' => 'app/Actions/Setup'],
] as $group) {
    foreach ($baseline[$group['key']] ?? [] as $action) {
        $relative = $group['dir'] . '/' . $action . '.php';
        if (! is_file($root . '/' . $relative)) {
            $failures[] = "$relative is missing";
            continue;
        }
        $source = p7Read($root, $relative);
        if (preg_match('/\bLivewire\\\\|App\\\\Livewire\\\\/', $source)) {
            $failures[] = "$relative depends on Livewire; Phase 7 Actions must remain transport-independent";
        }
    }
}

// Client writes are fully behind focused Client Actions after Phase 7.
if (preg_match('/::create\s*\(|->(?:create|update|delete|save|forceDelete)\s*\(|DB::transaction\s*\(/', $clientSurface)) {
    $failures[] = 'Client Livewire contains a direct persistence write instead of a Client Action boundary';
}

// Destructive Master Data behavior must no longer be hidden inside UI methods.
if (preg_match('/->(?:delete|forceDelete)\s*\(|::destroy\s*\(/', $masterSurface)) {
    $failures[] = 'Master Data Livewire contains a direct destructive persistence operation instead of a deletion Action';
}

$setupSources = [];
foreach ($baseline['setup_method_signatures'] ?? [] as $relative => $expected) {
    $source = p7Read($root, $relative);
    if ($source === '') {
        $failures[] = "$relative is missing";
        continue;
    }
    p7AssertMethodSurface($failures, $relative, $expected, p7Methods($source));
    $setupSources[$relative] = $source;
}
$setupCombined = implode("\n", $setupSources);
if (preg_match('/DB::transaction\s*\(|::create\s*\(|->(?:create|update|delete|save|forceDelete)\s*\(/', $setupCombined)) {
    $failures[] = 'Workflow/Task Pack setup Livewire contains direct persistence instead of Setup Actions';
}
foreach (['->saveWorkflow(', '->deleteWorkflow(', '->savePack(', '->deletePack(', '->saveItem(', '->deleteItem(', '->publishWorkflow('] as $serviceWrite) {
    if (str_contains($setupCombined, $serviceWrite)) {
        $failures[] = "Setup Livewire directly invokes service write {$serviceWrite}; route it through a Setup Action";
    }
}

foreach ($baseline['required_setup_components'] ?? [] as $component) {
    $relative = 'resources/views/components/setup/' . $component;
    if (! is_file($root . '/' . $relative)) $failures[] = "$relative is missing";
}

$workflowView = p7Read($root, 'resources/views/livewire/workflow-setup/index.blade.php');
$taskPackView = p7Read($root, 'resources/views/livewire/task-pack-setup/index.blade.php');
$orderWorkflowView = p7Read($root, 'resources/views/livewire/order-workflow-setup/index.blade.php');
$masterView = p7Read($root, 'resources/views/livewire/master-data/index.blade.php');
foreach ([
    [$workflowView, '<x-setup.page-header'],
    [$workflowView, '<x-setup.list'],
    [$workflowView, '<x-setup.editor-panel'],
    [$workflowView, '<x-setup.safe-delete-modal'],
    [$workflowView, '<x-setup.editor-modal'],
    [$taskPackView, '<x-setup.page-header'],
    [$taskPackView, '<x-setup.list'],
    [$taskPackView, '<x-setup.safe-delete-modal'],
    [$orderWorkflowView, '<x-setup.color-picker'],
] as [$view, $needle]) {
    if (! str_contains($view, $needle)) $failures[] = "Phase 7 shared setup primitive is not in active use: {$needle}";
}

// Runtime Master Data colors must keep feeding the centralized dynamic-color contract.
$allMasterViews = $masterView;
foreach (glob($root . '/resources/views/livewire/master-data/sections/*.blade.php') ?: [] as $file) {
    $allMasterViews .= "\n" . (string) file_get_contents($file);
}
if (! str_contains($allMasterViews, 'MasterColor::style(') || ! str_contains($allMasterViews, '<x-setup.color-picker')) {
    $failures[] = 'Master Data runtime color binding no longer uses MasterColor/shared color-picker contracts';
}
if (! str_contains($taskPackView, 'MasterColor::style(')) {
    $failures[] = 'Task Pack setup no longer renders runtime phase colors through MasterColor';
}

foreach ($baseline['protected_file_hashes'] ?? [] as $relative => $expected) {
    $path = $root . '/' . $relative;
    if (! is_file($path)) {
        $failures[] = "$relative is missing";
        continue;
    }
    if (! hash_equals((string) $expected, hash_file('sha256', $path))) {
        $failures[] = "$relative changed during Phase 7 (protected business/authorization boundary)";
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
} elseif (! hash_equals((string) ($baseline['migration_tree_hash'] ?? ''), p7TreeHash($root, 'database/migrations', '.php'))) {
    $failures[] = 'database/migrations changed during Phase 7; this structural phase does not require schema changes';
}
if (! is_file($root . '/quality/phase15-release-hardening.json') && ! hash_equals((string) ($baseline['css_tree_hash'] ?? ''), p7TreeHash($root, 'resources/css', '.css'))) {
    $failures[] = 'resources/css changed during Phase 7; this phase must not redesign the UI';
}

foreach ([
    'tests/Feature/Phase7AdministrationDeletionIntegrityTest.php',
    'tests/Feature/Phase7AdministrationArchitectureTest.php',
    'tests/Support/AdministrationPhase7Source.php',
] as $relative) {
    if (! is_file($root . '/' . $relative)) $failures[] = "$relative is missing";
}
$integrityTest = p7Read($root, 'tests/Feature/Phase7AdministrationDeletionIntegrityTest.php');
foreach ([
    'test_master_data_parent_cannot_be_deleted_while_child_records_exist',
    'test_product_category_delete_action_unassigns_product_instead_of_deleting_it',
    'test_task_pack_delete_action_unassigns_setup_phase_without_deleting_the_phase',
    'test_client_permanent_delete_action_removes_profile_children_but_preserves_history',
] as $testName) {
    if (! str_contains($integrityTest, $testName)) $failures[] = "Phase 7 deletion/reference integrity coverage missing {$testName}";
}

printf("Phase 7 administration decomposition gate\n");
printf("%-36s %8s\n", 'Metric', 'Current');
printf("%-36s %8d\n", 'MasterData/Index lines', $masterLines);
printf("%-36s %8d\n", 'Clients/Index lines', $clientLines);
printf("%-36s %8d\n", 'Master Data concerns', count($masterConcernFiles));
printf("%-36s %8d\n", 'Client concerns', count($clientConcernFiles));
printf("%-36s %8d\n", 'Setup components', count($baseline['required_setup_components'] ?? []));
printf("%-36s %8d\n", 'Master Data actions', count($baseline['required_master_actions'] ?? []));
printf("%-36s %8d\n", 'Client actions', count($baseline['required_client_actions'] ?? []));
printf("%-36s %8d\n", 'Setup actions', count($baseline['required_setup_actions'] ?? []));

if ($failures !== []) {
    fwrite(STDERR, "\nPhase 7 administration decomposition gate failed:\n - " . implode("\n - ", array_values(array_unique($failures))) . "\n");
    exit(1);
}

echo "\nPASS: Master Data and Clients are small compatibility coordinators, setup screens share Phase 7 primitives, destructive/admin writes cross explicit Actions, original method signatures and protected service/authorization semantics remain intact, CSS is unchanged and all pre-Phase-11 migrations remain hash-frozen, and deletion/reference integrity coverage is present.\n";
