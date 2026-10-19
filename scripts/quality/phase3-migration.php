#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = realpath(__DIR__ . '/../..');
if ($root === false) {
    fwrite(STDERR, "Unable to resolve project root.\n");
    exit(2);
}

$baselinePath = $root . '/quality/phase3-migration-baseline.json';

function p3Files(string $directory, string $suffix): array
{
    if (! is_dir($directory)) return [];
    $result = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), $suffix)) $result[] = $file->getPathname();
    }
    sort($result);
    return $result;
}

function p3Count(string $pattern, array $files): int
{
    $count = 0;
    foreach ($files as $file) {
        $matches = preg_match_all($pattern, (string) file_get_contents($file), $unused);
        $count += $matches === false ? 0 : $matches;
    }
    return $count;
}

function p3CssDebt(string $path): array
{
    $css = is_file($path) ? (string) file_get_contents($path) : '';
    preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $css, $hex);
    return [
        'bytes' => strlen($css),
        'important' => substr_count($css, '!important'),
        'hex' => count($hex[0] ?? []),
    ];
}

$viewFiles = p3Files($root . '/resources/views', '.blade.php');
$testFiles = p3Files($root . '/tests', '.php');
$authenticatedViews = array_values(array_filter($viewFiles, static fn (string $file): bool => ! str_ends_with(str_replace('\\', '/', $file), '/resources/views/welcome.blade.php')));

$metrics = [
    'public_css_files' => count(glob($root . '/public/css/*.css') ?: []),
    'direct_public_css_links' => p3Count('/<link\b[^>]*href=["\']\/css\/[^"\']+\.css/i', $viewFiles),
    'authenticated_style_blocks' => p3Count('/<style\b/i', $authenticatedViews),
    'blade_style_attributes' => p3Count('/\bstyle\s*=/', $viewFiles),
    'test_public_css_references' => p3Count('/public_path\(["\']css\/flowtrack-[^"\']+\.css["\']\)/', $testFiles),
];

$required = [
    'resources/css/application/prelude.css',
    'resources/css/application/core.css',
    'resources/css/application/after-core.css',
    'resources/css/application/after-dashboard.css',
    'resources/css/application/shared-components.css',
    'resources/css/modules/orders/index.css',
    'resources/css/modules/work/index.css',
    'resources/css/modules/setup/index.css',
    'resources/css/modules/dashboard/prototype.css',
    'resources/css/modules/dashboard/layout.css',
    'quality/css-finalization-manifest.json',
];

$failures = [];
foreach ($required as $relative) {
    if (! is_file($root . '/' . $relative)) $failures[] = "$relative is missing";
}
foreach ([
    'resources/css/flowtrack.css',
    'resources/css/legacy',
    'resources/css/migration',
] as $removedPath) {
    if (file_exists($root . '/' . $removedPath)) $failures[] = "$removedPath must remain removed after Phase 3 finalization";
}
if ($metrics['public_css_files'] !== 0) $failures[] = 'public/css still contains CSS files';
if ($metrics['direct_public_css_links'] !== 0) $failures[] = 'Blade still links directly to /css/*.css';
if ($metrics['authenticated_style_blocks'] !== 0) $failures[] = 'authenticated/application Blade views still contain <style> blocks';
if ($metrics['test_public_css_references'] !== 0) $failures[] = 'tests still read migrated FlowTrack CSS from public/css instead of managed source';

if (! is_file($baselinePath)) {
    $failures[] = 'quality/phase3-migration-baseline.json is missing';
    $baseline = ['ceilings' => []];
} else {
    $baseline = json_decode((string) file_get_contents($baselinePath), true, flags: JSON_THROW_ON_ERROR);
}

$inheritedExceptionPath = $root . '/quality/phase3-inherited-exceptions.json';
$inheritedExceptions = is_file($inheritedExceptionPath)
    ? json_decode((string) file_get_contents($inheritedExceptionPath), true, flags: JSON_THROW_ON_ERROR)
    : ['migrated_css_debt' => []];
$inheritedNotices = [];
$finalizationManifestPath = $root . '/quality/css-finalization-manifest.json';
$finalizationManifest = is_file($finalizationManifestPath)
    ? json_decode((string) file_get_contents($finalizationManifestPath), true, flags: JSON_THROW_ON_ERROR)
    : ['source_preserved_files' => []];
$finalizedPreserved = array_fill_keys($finalizationManifest['source_preserved_files'] ?? [], true);

foreach (($baseline['ceilings'] ?? []) as $name => $limit) {
    if (! isset($metrics[$name]) || $metrics[$name] <= (int) $limit) continue;

    $exception = $inheritedExceptions['ceilings'][$name] ?? null;
    $exceptionLimit = is_array($exception) ? (int) ($exception['ceiling'] ?? -1) : (is_numeric($exception) ? (int) $exception : -1);
    if ($exceptionLimit >= 0 && $metrics[$name] <= $exceptionLimit) {
        $inheritedNotices[] = "$name inherited at {$metrics[$name]} (original ceiling $limit; exception ceiling $exceptionLimit)";
        continue;
    }

    $suffix = $exceptionLimit >= 0 ? "; exception ceiling $exceptionLimit" : '';
    $failures[] = "$name increased to {$metrics[$name]} (ceiling $limit$suffix)";
}

foreach (($baseline['migrated_css_debt'] ?? []) as $relative => $limits) {
    if (isset($finalizedPreserved[$relative])) continue; // governed by css-modularization aggregate ceiling
    $current = p3CssDebt($root . '/' . $relative);
    foreach (['bytes', 'important', 'hex'] as $key) {
        $baselineLimit = (int) ($limits[$key] ?? 0);
        if ($current[$key] <= $baselineLimit) continue;

        $exceptionLimit = $inheritedExceptions['migrated_css_debt'][$relative][$key] ?? null;
        if ($exceptionLimit !== null && $current[$key] <= (int) $exceptionLimit) {
            $inheritedNotices[] = "$relative $key inherited at {$current[$key]} (original ceiling $baselineLimit; exception ceiling $exceptionLimit)";
            continue;
        }

        $failures[] = "$relative $key increased to {$current[$key]}";
    }
}

foreach (($inheritedExceptions['compatibility_css_debt'] ?? []) as $relative => $limits) {
    $path = $root . '/' . $relative;
    if (! is_file($path)) continue; // deletion is the desired finalization state
    $current = p3CssDebt($path);
    foreach (['bytes', 'important', 'hex'] as $key) {
        $limit = (int) ($limits[$key] ?? 0);
        if ($current[$key] > $limit) {
            $failures[] = "$relative $key increased to {$current[$key]} (frozen compatibility ceiling $limit)";
        }
    }
    $inheritedNotices[] = "$relative frozen compatibility debt: {$current['bytes']} bytes / {$current['important']} !important / {$current['hex']} hex";
}

$layout = (string) file_get_contents($root . '/resources/views/layouts/app.blade.php');
foreach ([
    "@vite('resources/css/application/prelude.css')",
    "@vite('resources/css/application/after-core.css')",
    "@vite('resources/css/application/after-dashboard.css')",
    "@vite('resources/css/application/shared-components.css')",
] as $needle) {
    if (! str_contains($layout, $needle)) $failures[] = "layout missing $needle";
}

printf("Phase 3 migration gate\n%-42s %10s\n", 'Metric', 'Current');
foreach ($metrics as $name => $value) printf("%-42s %10d\n", $name, $value);
if ($inheritedNotices !== []) {
    echo "\nInherited Phase 3 exceptions (non-increasing):\n - " . implode("\n - ", array_values(array_unique($inheritedNotices))) . "\n";
}

if ($failures !== []) {
    fwrite(STDERR, "\nPhase 3 migration gate failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "\nPASS: Phase 3 CSS is source-managed and modularized; the monolith/legacy paths are removed, Blade style blocks remain removed, and preserved debt did not increase.\n";
