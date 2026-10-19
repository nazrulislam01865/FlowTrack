#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = realpath(__DIR__.'/../..');
if ($root === false) exit(2);
$baselinePath = $root.'/quality/phase13-javascript.json';
if (!is_file($baselinePath)) {
    fwrite(STDERR, "Phase 13 baseline is missing.\n");
    exit(2);
}
$baseline = json_decode((string) file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR);
$failures = [];

function p13Read(string $root, string $relative): string
{
    $path = $root.'/'.$relative;
    return is_file($path) ? (string) file_get_contents($path) : '';
}

function p13TreeHash(string $root, string $relativeDir, string $suffix): string
{
    $dir = $root.'/'.$relativeDir;
    $files = [];
    if (is_dir($dir)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), $suffix)) $files[] = $file->getPathname();
        }
    }
    sort($files);
    $ctx = hash_init('sha256');
    foreach ($files as $file) {
        hash_update($ctx, str_replace($root.'/', '', $file));
        hash_update($ctx, "\0");
        hash_update($ctx, (string) file_get_contents($file));
        hash_update($ctx, "\0");
    }
    return hash_final($ctx);
}

function p13JsFiles(string $root): array
{
    $dir = $root.'/resources/js';
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) if ($file->isFile() && $file->getExtension() === 'js') $files[] = $file->getPathname();
    sort($files);
    return $files;
}

foreach ($baseline['required_core'] as $file) {
    if (!is_file($root.'/resources/js/core/'.$file)) $failures[] = 'Missing core JS module: '.$file;
}
foreach ($baseline['required_components'] as $file) {
    if (!is_file($root.'/resources/js/components/'.$file)) $failures[] = 'Missing component JS module: '.$file;
}
foreach ($baseline['required_features'] as $file) {
    if (!is_file($root.'/resources/js/features/'.$file)) $failures[] = 'Missing feature JS module: '.$file;
}
$browserApiPath = is_file($root.'/resources/js/core/browser-api.js') ? 'resources/js/core/browser-api.js' : 'resources/js/compatibility/browser-bridge.js';
if (!is_file($root.'/'.$browserApiPath)) $failures[] = 'Missing FlowTrack browser API bridge.';

$app = p13Read($root, 'resources/js/app.js');
$appLines = substr_count($app, "\n") + 1;
if ($appLines > (int) $baseline['app_js_max_lines']) $failures[] = "resources/js/app.js grew to {$appLines} lines; composition-root budget is {$baseline['app_js_max_lines']}.";
foreach (['bindNavigationLifecycle', 'bootRealtimeClient', 'bootRouteFeatures'] as $needle) {
    if (!str_contains($app, $needle)) $failures[] = 'app.js composition root missing '.$needle;
}

$navigation = p13Read($root, 'resources/js/core/navigation.js');
foreach (["'livewire:init'", "'livewire:navigating'", "'livewire:navigated'", 'lifecycleState.bound'] as $needle) {
    if (!str_contains($navigation, $needle)) $failures[] = 'Central navigation lifecycle missing '.$needle;
}
foreach (p13JsFiles($root) as $file) {
    if (str_ends_with($file, '/core/navigation.js')) continue;
    $source = (string) file_get_contents($file);
    if (str_contains($source, "document.addEventListener('livewire:navigated'") || str_contains($source, "document.addEventListener('livewire:navigating'") || str_contains($source, "document.addEventListener('livewire:init'")) {
        $failures[] = str_replace($root.'/', '', $file).' registers a top-level Livewire navigation lifecycle outside core/navigation.js.';
    }
}

$realtime = p13Read($root, 'resources/js/core/realtime.js');
foreach (['class FlowTrackReverbClient', 'let client = null;', 'export const bootRealtimeClient', 'scheduleReconnect()', 'Math.min(15000, 500 * Math.pow(2', "this.channels = new Map()"] as $needle) {
    if (!str_contains($realtime, $needle)) $failures[] = 'Central realtime client missing '.$needle;
}
$events = p13Read($root, 'resources/js/core/events.js');
foreach (['REALTIME_EVENTS', 'LIVEWIRE_EVENTS', 'BROWSER_EVENTS', "WORKSPACE_REFRESH: 'flowtrack.refresh'", "NOTIFICATION: 'flowtrack.notification'"] as $needle) {
    if (!str_contains($events, $needle)) $failures[] = 'Event-contract module missing '.$needle;
}
$notifications = p13Read($root, 'resources/js/features/notifications.js');
$workspace = p13Read($root, 'resources/js/features/workspace-refresh.js');
foreach ([$notifications, $workspace] as $source) {
    if (!str_contains($source, 'REALTIME_EVENTS')) $failures[] = 'Realtime feature is not consuming centralized realtime event contracts.';
    if (str_contains($source, 'window.FlowTrackRealtime')) $failures[] = 'Realtime feature still depends on legacy window.FlowTrackRealtime.';
}
if (!str_contains($notifications, 'unreadFallbackIntervalMs = 60000')) $failures[] = 'Notification polling fallback contract changed.';
if (!str_contains($workspace, 'startPolling(60000)') || !str_contains($workspace, 'startPolling(30000)')) $failures[] = 'Workspace polling fallback contract changed.';

$bridge = p13Read($root, $browserApiPath);
foreach (['window.FlowTrack = existing;', 'inlineEdit: createInlineEdit', 'searchSelect: createSearchSelect', 'masterColor,', 'existing.realtime ='] as $needle) {
    if (!str_contains($bridge, $needle)) $failures[] = 'Namespaced browser API missing '.$needle;
}
$phase15Final = is_file($root.'/quality/phase15-release-hardening.json');
if (!$phase15Final) {
    foreach ($baseline['legacy_aliases'] as $alias) {
        if (!str_contains($bridge, $alias)) $failures[] = 'Compatibility bridge missing legacy alias '.$alias;
    }
}
foreach (p13JsFiles($root) as $file) {
    if (str_ends_with($file, '/compatibility/browser-bridge.js') || str_ends_with($file, '/core/browser-api.js')) continue;
    $source = (string) file_get_contents($file);
    if (preg_match('/window\.FlowTrack[A-Z][A-Za-z0-9_]*/', $source, $match)) {
        $failures[] = str_replace($root.'/', '', $file).' owns deprecated browser global '.$match[0].' outside the browser API boundary.';
    }
}
if ($phase15Final) {
    foreach ($baseline['legacy_aliases'] as $alias) {
        if (str_contains($bridge, $alias)) $failures[] = 'Phase 15 browser API still exposes removed legacy alias '.$alias;
    }
}

$layout = p13Read($root, 'resources/views/layouts/app.blade.php');
if (!str_contains($layout, "'resources/js/app.js'")) $failures[] = 'Layout is not loading the Vite JavaScript composition root.';
if (preg_match('#/js/flowtrack-[A-Za-z0-9._-]+\\.js#', $layout)) $failures[] = 'Layout still loads unmanaged legacy /public/js FlowTrack scripts.';

$publicJs = [];
if (is_dir($root.'/public/js')) {
    foreach (glob($root.'/public/js/*.js') ?: [] as $file) $publicJs[] = $file;
}
if (count($publicJs) > (int) $baseline['public_js_max_files']) $failures[] = 'Unmanaged public/js scripts remain: '.count($publicJs);

$bladeFiles = [];
$viewIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/resources/views', FilesystemIterator::SKIP_DOTS));
foreach ($viewIterator as $file) if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) $bladeFiles[] = $file->getPathname();
foreach ($bladeFiles as $file) {
    $source = (string) file_get_contents($file);
    if (preg_match('/<script[^>]+src=["\']https?:\\/\\//i', $source)) $failures[] = str_replace($root.'/', '', $file).' still loads an unmanaged third-party JavaScript CDN.';
    if (preg_match('/window\\.FlowTrack[A-Z][A-Za-z0-9_]*/', $source, $match)) $failures[] = str_replace($root.'/', '', $file).' still calls deprecated '.$match[0].' directly.';
}
$bladeCombined = implode("\n", array_map(static fn ($file) => (string) file_get_contents($file), $bladeFiles));
if (!str_contains($bladeCombined, 'window.FlowTrack.ui.')) $failures[] = 'Blade bindings are not using the Phase 13 namespaced browser bridge.';

$routeLoader = p13Read($root, 'resources/js/features/route-loader.js');
$bulk = p13Read($root, 'resources/js/features/bulk-order-import.js');
$bulkView = p13Read($root, 'resources/views/pages/bulk-order-import.blade.php');
if (!str_contains($routeLoader, "import('./bulk-order-import.js')")) $failures[] = 'Bulk Order Import is not route-loaded as a feature chunk.';
if (!str_contains($bulk, "import * as XLSX from 'xlsx';")) $failures[] = 'Bulk Order Import does not use the Vite-managed SheetJS dependency.';
if (str_contains($bulkView, 'cdn.jsdelivr.net/npm/xlsx') || str_contains($bulkView, 'xlsx.full.min.js')) $failures[] = 'Bulk Order Import still loads SheetJS from a runtime CDN.';

$package = json_decode(p13Read($root, 'package.json'), true, 512, JSON_THROW_ON_ERROR);
$lock = json_decode(p13Read($root, 'package-lock.json'), true, 512, JSON_THROW_ON_ERROR);
$packageXlsx = (string) ($package['devDependencies']['xlsx'] ?? $package['dependencies']['xlsx'] ?? '');
$lockXlsx = $lock['packages']['node_modules/xlsx'] ?? [];
if ($phase15Final) {
    $phase15SheetJs = 'https://cdn.sheetjs.com/xlsx-0.20.3/xlsx-0.20.3.tgz';
    if ($packageXlsx !== $phase15SheetJs) $failures[] = 'Phase 15 must use the authoritative SheetJS 0.20.3 tarball.';
    if (($lockXlsx['version'] ?? null) !== '0.20.3' || ($lockXlsx['resolved'] ?? null) !== $phase15SheetJs) $failures[] = 'package-lock.json does not lock the authoritative SheetJS 0.20.3 tarball.';
} else {
    if ($packageXlsx !== $baseline['package_lock_xlsx_version']) $failures[] = 'package.json SheetJS version is not pinned to the approved Phase 13 compatibility version.';
    if (($lockXlsx['version'] ?? null) !== $baseline['package_lock_xlsx_version']) $failures[] = 'package-lock.json does not lock SheetJS 0.18.5.';
    if (($lockXlsx['integrity'] ?? null) !== $baseline['xlsx_integrity']) $failures[] = 'package-lock.json SheetJS integrity changed.';
}

foreach (['scripts/quality/js-syntax.mjs', 'tests/JavaScript/phase13-unit.mjs', 'tests/Browser/phase13-smoke.mjs', 'tests/Feature/Phase13JavascriptArchitectureTest.php', 'docs/javascript-realtime.md', 'docs/refactor/PHASE_13_IMPLEMENTATION.md'] as $relative) {
    if (!is_file($root.'/'.$relative)) $failures[] = $relative.' is missing.';
}

// Phase 15 is allowed to remove proven-dead browser/CSS compatibility assets and add
// observability routing. Earlier Phase 13 snapshots remain exact when Phase 15 is absent.
if (!$phase15Final) {
    if (!hash_equals($baseline['blade_tree_hash'], p13TreeHash($root, 'resources/views', '.blade.php'))) $failures[] = 'Blade changed after the approved Phase 13 JavaScript binding migration.';
    if (!hash_equals($baseline['css_tree_hash'], p13TreeHash($root, 'resources/css', '.css'))) $failures[] = 'CSS changed during Phase 13.';
    if (!hash_equals($baseline['routes_web_hash'], hash_file('sha256', $root.'/routes/web.php'))) $failures[] = 'Web routes changed during Phase 13.';
}
if (!hash_equals($baseline['migration_tree_hash'], p13TreeHash($root, 'database/migrations', '.php'))) $failures[] = 'Database migrations changed during Phase 13.';
if (!hash_equals($baseline['access_control_hash'], hash_file('sha256', $root.'/app/Services/AccessControlService.php'))) $failures[] = 'AccessControlService changed during Phase 13.';

if ($failures) {
    fwrite(STDERR, "Phase 13 JavaScript/realtime architecture FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, ' - '.$failure."\n");
    exit(1);
}

$jsCount = count(p13JsFiles($root));
echo "Phase 13 JavaScript/realtime architecture PASS\n";
echo " - app.js composition root: {$appLines} lines\n";
echo " - modular resources/js files: {$jsCount}\n";
echo " - unmanaged public/js scripts: 0\n";
echo " - deprecated broad globals: removed or compatibility-boundary only\n";
echo " - realtime/event lifecycle: centralized\n";
echo $phase15Final ? " - SheetJS: authoritative 0.20.3 tarball, Vite-managed and route-loaded\n" : " - SheetJS: Vite-managed, route-loaded, lockfile pinned\n";
