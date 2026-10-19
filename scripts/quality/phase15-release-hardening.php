#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$baseline = json_decode((string) file_get_contents($root.'/quality/phase15-release-hardening.json'), true, 512, JSON_THROW_ON_ERROR);
$legacy = json_decode((string) file_get_contents($root.'/quality/phase15-legacy-exceptions.json'), true, 512, JSON_THROW_ON_ERROR);
$failures = [];

$read = static fn (string $rel): string => is_file($root.'/'.$rel) ? (string) file_get_contents($root.'/'.$rel) : '';
$treeHash = static function (string $relative) use ($root): string {
    $base = $root.'/'.$relative;
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) if ($file->isFile()) $files[] = $file->getPathname();
    sort($files, SORT_STRING);
    $ctx = hash_init('sha256');
    foreach ($files as $file) {
        $rel = str_replace('\\', '/', substr($file, strlen($base) + 1));
        hash_update($ctx, $rel."\0".hash_file('sha256', $file, true));
    }
    return hash_final($ctx);
};
$phpFiles = static function (string $relative) use ($root): array {
    $base = $root.'/'.$relative;
    $files = [];
    if (! is_dir($base)) return [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) if ($file->isFile() && $file->getExtension() === 'php') $files[] = $file->getPathname();
    sort($files, SORT_STRING);
    return $files;
};

foreach ($baseline['required_files'] as $file) {
    if (! is_file($root.'/'.$file)) $failures[] = "required Phase 15 file missing: {$file}";
}
foreach ($baseline['removed_files'] as $file) {
    if (is_file($root.'/'.$file)) $failures[] = "proven-dead Phase 15 asset returned: {$file}";
}

$package = json_decode($read('package.json'), true, 512, JSON_THROW_ON_ERROR);
$lock = json_decode($read('package-lock.json'), true, 512, JSON_THROW_ON_ERROR);
$expectedXlsx = (string) ($baseline['dependency_policy']['xlsx'] ?? '');
if (($package['devDependencies']['xlsx'] ?? null) !== $expectedXlsx) $failures[] = 'package.json is not using the approved SheetJS 0.20.3 source.';
if (($lock['packages']['node_modules/xlsx']['version'] ?? null) !== '0.20.3' || ($lock['packages']['node_modules/xlsx']['resolved'] ?? null) !== $expectedXlsx) $failures[] = 'package-lock.json is not locked to SheetJS 0.20.3.';

$workflow = $read('.github/workflows/flowtrack-ci.yml');
foreach ([
    'composer install --no-interaction --prefer-dist',
    'npm ci',
    'vendor/bin/pint --test',
    'php artisan test',
    'npm run quality:phase15',
    'npm run build',
    'npm run quality:bundle',
    'composer audit --locked',
    'npm audit --audit-level=high',
    'npm run visual:test',
] as $needle) {
    if (! str_contains($workflow, $needle)) $failures[] = "CI pipeline missing required gate: {$needle}";
}

// Blade style blocks are no longer allowed in application views.
$styleBlocks = 0;
$viewBase = $root.'/resources/views';
$viewIt = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewBase, FilesystemIterator::SKIP_DOTS));
foreach ($viewIt as $file) {
    if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) continue;
    $source = (string) file_get_contents($file->getPathname());
    $styleBlocks += preg_match_all('/<style\b/i', $source);
}
if ($styleBlocks !== (int) $baseline['targets']['blade_style_blocks']) $failures[] = "Blade style-block budget is {$styleBlocks}; expected 0";

// No unrestricted Eloquent assignment may return.
$guardedEmpty = 0;
foreach ($phpFiles('app/Models') as $file) {
    $guardedEmpty += preg_match_all('/protected\s+\$guarded\s*=\s*\[\s*\]\s*;/', (string) file_get_contents($file));
}
if ($guardedEmpty !== (int) $baseline['targets']['guarded_empty']) $failures[] = "unrestricted model guarded=[] occurrences: {$guardedEmpty}";

// Only the namespaced FlowTrack browser API is allowed.
$broadGlobals = 0;
foreach (['resources/js', 'resources/views'] as $relative) {
    $base = $root.'/'.$relative;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (! $file->isFile()) continue;
        $name = $file->getFilename();
        if (! str_ends_with($name, '.js') && ! str_ends_with($name, '.blade.php')) continue;
        $broadGlobals += preg_match_all('/window\.FlowTrack[A-Z][A-Za-z0-9_]*/', (string) file_get_contents($file->getPathname()));
    }
}
if ($broadGlobals !== (int) $baseline['targets']['broad_flowtrack_globals']) $failures[] = "deprecated broad window.FlowTrack* references: {$broadGlobals}";

$vite = $read('vite.config.js');
$layout = $read('resources/views/layouts/app.blade.php');
if (! str_contains($vite, "'resources/css/app.css'")) $failures[] = 'Vite no longer uses resources/css/app.css directly.';
if (str_contains($vite, 'splitFlowtrackCss') || str_contains($vite, 'resources/css/generated/flowtrack-')) $failures[] = 'obsolete split/generated CSS delivery returned to vite.config.js.';
if (! str_contains($layout, "'resources/css/app.css'")) $failures[] = 'authenticated layout is not loading app.css directly.';
if (str_contains($layout, 'resources/css/generated/flowtrack-')) $failures[] = 'authenticated layout still references generated FlowTrack CSS chunks.';

// Legacy services: exact caller allowlist, definitions excluded. No transport/application boundary may call them.
foreach ($legacy['legacy_services'] as $class => $allowed) {
    sort($allowed, SORT_STRING);
    $actual = [];
    foreach ($phpFiles('app') as $file) {
        if (basename($file) === $class.'.php') continue;
        $source = (string) file_get_contents($file);
        if (preg_match('/\b'.preg_quote($class, '/').'\b/', $source)) {
            $actual[] = str_replace('\\', '/', substr($file, strlen($root) + 1));
        }
    }
    sort($actual, SORT_STRING);
    if ($actual !== $allowed) {
        $failures[] = $class.' callers changed. allowed='.json_encode($allowed).' actual='.json_encode($actual);
    }
}
foreach (['app/Livewire', 'app/Actions', 'app/Queries'] as $relative) {
    foreach ($phpFiles($relative) as $file) {
        $source = (string) file_get_contents($file);
        if (preg_match('/\bLegacy(?:Job|Inquiry|Dashboard)Service\b/', $source, $m)) {
            $failures[] = str_replace($root.'/', '', $file).' directly references '.$m[0];
        }
    }
}

// Active compatibility CSS may shrink but cannot grow or gain new files.
$expectedCss = [];
$totalBytes = 0;
$totalImportant = 0;
foreach ($legacy['legacy_css'] as $entry) {
    $expectedCss[$entry['file']] = true;
    $path = $root.'/'.$entry['file'];
    if (! is_file($path)) continue; // deletion is allowed
    $source = (string) file_get_contents($path);
    $bytes = filesize($path);
    $important = substr_count($source, '!important');
    $totalBytes += $bytes;
    $totalImportant += $important;
    if ($bytes > (int) $entry['bytes']) $failures[] = $entry['file'].' grew beyond Phase 15 legacy byte ceiling.';
    if ($important > (int) $entry['important']) $failures[] = $entry['file'].' grew beyond Phase 15 !important ceiling.';
}
$compatDir = $root.'/resources/css/legacy/compatibility';
foreach (glob($compatDir.'/*.css') ?: [] as $file) {
    $rel = str_replace('\\', '/', substr($file, strlen($root) + 1));
    if (! isset($expectedCss[$rel])) $failures[] = 'new unregistered compatibility CSS file: '.$rel;
}

$inventory = json_decode($read('quality/phase11-query-inventory.json'), true, 512, JSON_THROW_ON_ERROR);
$unsafe = (int) ($inventory['unsafe_unbounded_count'] ?? -1);
if ($unsafe !== (int) $baseline['targets']['unsafe_unbounded_reads']) $failures[] = "Phase 11 unsafe-unbounded read inventory is {$unsafe}; expected 0";

// Observability and alert ownership.
foreach ([
    'app/Services/Observability/OperationsMetrics.php' => ['recordRequest(', "'cache_hits'", 'recordQueueDelay(', 'recordRealtimeClient(', 'snapshot(', 'alerts('],
    'routes/console.php' => ['flowtrack:observability:snapshot', 'flowtrack:observability:check', 'flowtrack.observability.alert'],
    'resources/js/features/realtime-telemetry.js' => ['flowtrack-realtime-telemetry-url'],
] as $file => $needles) {
    $source = $read($file);
    foreach ($needles as $needle) if (! str_contains($source, $needle)) $failures[] = "{$file} missing observability contract {$needle}";
}

$protectedCurrent = [
    'routes_web' => hash_file('sha256', $root.'/routes/web.php'),
    'access_control' => hash_file('sha256', $root.'/app/Services/AccessControlService.php'),
    'migrations' => $treeHash('database/migrations'),
    'composer_lock' => hash_file('sha256', $root.'/composer.lock'),
    'package_lock' => hash_file('sha256', $root.'/package-lock.json'),
];
foreach ($protectedCurrent as $name => $hash) {
    if (! hash_equals((string) ($baseline['protected'][$name] ?? ''), $hash)) $failures[] = "protected final boundary changed after Phase 15 baseline: {$name}";
}

$currentState = $read('docs/refactor/CURRENT_STATE.md');
if (! str_contains($currentState, 'current executable state — Phase 15')) $failures[] = 'CURRENT_STATE is not Phase 15.';
if (! str_contains($read('docs/ARCHITECTURE.md'), 'Phase 15 release governance')) $failures[] = 'ARCHITECTURE.md does not document Phase 15 governance.';

if ($failures !== []) {
    fwrite(STDERR, "Phase 15 release hardening FAIL\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Phase 15 CI/CD, observability and release hardening PASS\n";
echo " - CI: architecture + Pint/PHPUnit + Vite/bundle + dependency audits + visual/release jobs\n";
echo " - architecture: 0 Blade style blocks, 0 guarded=[], 0 deprecated broad FlowTrack globals\n";
echo " - reads: Phase 11 unsafe-unbounded inventory remains 0\n";
echo " - generated CSS split delivery: removed; app.css is direct Vite entry\n";
echo " - legacy compatibility: registered callers/files only; current CSS {$totalBytes} bytes / {$totalImportant} !important (shrinking-only)\n";
echo " - observability: rolling HTTP/query/memory/cache/queue/realtime metrics + scheduled alerts\n";
echo " - protected routes/migrations/RBAC/dependency lockfiles: unchanged\n";
