#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = realpath(__DIR__.'/../..');
if ($root === false) {
    fwrite(STDERR, "Unable to resolve project root.\n");
    exit(2);
}

$manifestPath = $root.'/quality/css-finalization-manifest.json';
if (! is_file($manifestPath)) {
    fwrite(STDERR, "Missing quality/css-finalization-manifest.json.\n");
    exit(2);
}

$manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
$failures = [];
$rows = [];

foreach (($manifest['deleted_paths'] ?? []) as $relative) {
    if (file_exists($root.'/'.$relative)) {
        $failures[] = "removed CSS path returned: {$relative}";
    }
}

foreach (($manifest['composition_entries'] ?? []) as $relative) {
    if (! is_file($root.'/'.$relative)) {
        $failures[] = "missing CSS composition entry: {$relative}";
    }
}

foreach (($manifest['source_preserved_files'] ?? []) as $relative) {
    if (! is_file($root.'/'.$relative)) {
        $failures[] = "missing migrated CSS owner: {$relative}";
    }
}
$preservedMetrics = ['bytes' => 0, 'important' => 0, 'hex' => 0];
foreach (($manifest['source_preserved_files'] ?? []) as $relative) {
    $path = $root.'/'.$relative;
    if (! is_file($path)) continue;
    $source = (string) file_get_contents($path);
    $preservedMetrics['bytes'] += filesize($path) ?: 0;
    $preservedMetrics['important'] += substr_count($source, '!important');
    preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $source, $hexMatches);
    $preservedMetrics['hex'] += count($hexMatches[0] ?? []);
}
foreach (($manifest['source_preserved_aggregate_ceiling'] ?? []) as $metric => $ceiling) {
    if (($preservedMetrics[$metric] ?? 0) > (int) $ceiling) {
        $failures[] = "preserved CSS {$metric} debt increased to {$preservedMetrics[$metric]} (ceiling {$ceiling})";
    }
}

$cssRoot = $root.'/resources/css';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cssRoot, FilesystemIterator::SKIP_DOTS));
$cssFiles = [];
$largestFile = '';
$largestBytes = 0;
$maxBytes = (int) ($manifest['max_css_file_bytes'] ?? 100000);

foreach ($iterator as $file) {
    if (! $file->isFile() || strtolower($file->getExtension()) !== 'css') continue;

    $path = $file->getPathname();
    $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
    $cssFiles[] = $relative;
    $bytes = $file->getSize();

    if ($bytes > $largestBytes) {
        $largestBytes = $bytes;
        $largestFile = $relative;
    }
    if ($bytes > $maxBytes) {
        $failures[] = "CSS source exceeds {$maxBytes} bytes: {$relative} ({$bytes})";
    }
    if (preg_match('~/(?:legacy|compatibility|migration)/~i', '/'.$relative.'/')) {
        $failures[] = "obsolete CSS ownership directory returned: {$relative}";
    }
    if (basename($relative) === 'flowtrack.css') {
        $failures[] = 'flowtrack.css monolith returned';
    }

    $source = (string) file_get_contents($path);
    if (preg_match_all('/@import\s+[\'\"]([^\'\"]+)[\'\"]\s*;/', $source, $matches)) {
        foreach ($matches[1] as $import) {
            if (preg_match('~^(?:https?:|//|data:)~i', $import)) continue;
            $resolved = realpath(dirname($path).'/'.$import);
            if ($resolved === false || ! is_file($resolved)) {
                $failures[] = "missing CSS import target in {$relative}: {$import}";
            }
            if (str_contains(str_replace('\\', '/', $import), '/legacy/') || str_contains($import, 'compatibility/')) {
                $failures[] = "legacy CSS import remains in {$relative}: {$import}";
            }
        }
    }
}

sort($cssFiles);

$compositionOnly = [
    'resources/css/app.css',
    'resources/css/application/core.css',
    'resources/css/application/prelude.css',
    'resources/css/application/after-core.css',
    'resources/css/application/after-dashboard.css',
    'resources/css/application/shared-components.css',
    'resources/css/modules/inquiries/core.css',
    'resources/css/modules/setup/master-data.css',
    'resources/css/modules/orders/detail-prototype.css',
    'resources/css/modules/orders/index.css',
    'resources/css/modules/work/index.css',
    'resources/css/modules/setup/index.css',
];
foreach ($compositionOnly as $relative) {
    $path = $root.'/'.$relative;
    if (! is_file($path)) continue;
    $source = (string) file_get_contents($path);
    $withoutComments = preg_replace('~/\*.*?\*/~s', '', $source) ?? $source;
    $withoutImports = preg_replace('/@import\s+[\'\"][^\'\"]+[\'\"]\s*;/', '', $withoutComments) ?? $withoutComments;
    if (trim($withoutImports) !== '') {
        $failures[] = "composition entry contains selectors/declarations: {$relative}";
    }
}

$layout = (string) file_get_contents($root.'/resources/views/layouts/app.blade.php');
$vite = (string) file_get_contents($root.'/vite.config.js');
foreach (['resources/css/legacy', 'resources/css/migration', 'resources/css/flowtrack.css', 'legacy-prototype.css'] as $needle) {
    if (str_contains($layout, $needle)) $failures[] = "layout still references {$needle}";
    if (str_contains($vite, $needle)) $failures[] = "Vite still references {$needle}";
}
foreach ([
    "@vite('resources/css/application/prelude.css')",
    "@vite('resources/css/application/after-core.css')",
    "@vite('resources/css/application/after-dashboard.css')",
    "@vite('resources/css/application/shared-components.css')",
] as $needle) {
    if (! str_contains($layout, $needle)) $failures[] = "layout missing modular CSS entry {$needle}";
}

$rows[] = ['CSS files', count($cssFiles)];
$rows[] = ['Largest CSS source', $largestFile.' ('.$largestBytes.' B)'];
$rows[] = ['Per-file ceiling', $maxBytes.' B'];
$rows[] = ['Preserved CSS bytes', (string) $preservedMetrics['bytes']];
$rows[] = ['Preserved !important', (string) $preservedMetrics['important']];
$rows[] = ['Preserved hex literals', (string) $preservedMetrics['hex']];
$rows[] = ['flowtrack.css', file_exists($root.'/resources/css/flowtrack.css') ? 'present' : 'removed'];
$rows[] = ['legacy directory', is_dir($root.'/resources/css/legacy') ? 'present' : 'removed'];
$rows[] = ['migration directory', is_dir($root.'/resources/css/migration') ? 'present' : 'removed'];

printf("CSS modularization governance\n%-28s %s\n", 'Metric', 'Value');
foreach ($rows as [$label, $value]) printf("%-28s %s\n", $label, (string) $value);

if ($failures !== []) {
    fwrite(STDERR, "\nCSS modularization gate failed:\n - ".implode("\n - ", array_values(array_unique($failures)))."\n");
    exit(1);
}

echo "\nPASS: FlowTrack CSS is modular, import-safe, and has no monolithic/legacy source tree.\n";
