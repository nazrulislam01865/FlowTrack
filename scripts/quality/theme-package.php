#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failures = [];
$required = [
    'resources/theme/flowtrack/settings.css',
    'resources/theme/flowtrack/aliases.css',
    'resources/theme/flowtrack/system.css',
    'resources/theme/flowtrack/core.css',
    'resources/theme/flowtrack/theme.css',
    'resources/theme/flowtrack/components/sidebar.css',
    'resources/theme/flowtrack/components/management-dashboard.css',
    'resources/theme/flowtrack/README.md',
    'docs/THEME_PACKAGE.md',
];
foreach ($required as $file) if (! is_file($root.'/'.$file)) $failures[] = "missing theme package file: {$file}";

$read = static fn (string $rel): string => is_file($root.'/'.$rel) ? (string) file_get_contents($root.'/'.$rel) : '';
$settings = $read('resources/theme/flowtrack/settings.css');
foreach ([
    '--ft-theme-primary:', '--ft-theme-page-bg:', '--ft-theme-dashboard-page-bg:', '--ft-theme-surface:', '--ft-theme-text-primary:',
    '--ft-theme-border:', '--ft-theme-root-font-size:', '--ft-theme-font-family:', '--ft-theme-font-family-mono:', '--ft-theme-font-size-base:',
    '--ft-theme-sidebar-width:', '--ft-theme-sidebar-bg:', '--ft-theme-sidebar-active:',
] as $token) if (! str_contains($settings, $token)) $failures[] = "settings.css missing {$token}";

// settings.css is the only package file allowed to contain static color literals.
$themeRoot = $root.'/resources/theme/flowtrack';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($themeRoot, FilesystemIterator::SKIP_DOTS));
$rawColorFiles = [];
foreach ($it as $file) {
    if (! $file->isFile() || $file->getExtension() !== 'css' || $file->getFilename() === 'settings.css') continue;
    $source = preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($file->getPathname())) ?? '';
    if (preg_match('/#[0-9a-fA-F]{3,8}\b|\brgba?\s*\(/', $source)) {
        $rawColorFiles[] = str_replace($root.'/', '', $file->getPathname());
    }
}
if ($rawColorFiles !== []) $failures[] = 'raw static colors outside settings.css: '.implode(', ', $rawColorFiles);

$aliases = $read('resources/theme/flowtrack/aliases.css');
foreach (['--ft-color-brand-primary: var(--ft-theme-primary)', '--mgmt-teal: var(--ft-theme-primary)', '--ft-sidebar-width: var(--ft-theme-sidebar-width)'] as $needle) {
    if (! str_contains($aliases, $needle)) $failures[] = "theme alias missing: {$needle}";
}

$sidebar = $read('resources/theme/flowtrack/components/sidebar.css');
foreach (['var(--ft-theme-sidebar-width)', 'var(--ft-theme-sidebar-active)', 'var(--ft-theme-sidebar-item-font-size)'] as $needle) {
    if (! str_contains($sidebar, $needle)) $failures[] = "sidebar is not theme-controlled: {$needle}";
}

$dashboard = $read('resources/theme/flowtrack/components/management-dashboard.css');
if (str_contains($dashboard, '--mgmt-teal:')) $failures[] = 'Dashboard component is redefining theme values instead of consuming aliases.';
if (! str_contains($dashboard, 'var(--ft-theme-dashboard-page-bg)')) $failures[] = 'Dashboard package component is not consuming its preserved central background token.';


// Background centralization: every screen-level canvas must resolve to one pure-white token.
foreach ([
    '--ft-theme-page-bg: #ffffff;',
    '--ft-theme-dashboard-page-bg: var(--ft-theme-page-bg);',
    '--ft-theme-orders-list-page-bg: var(--ft-theme-page-bg);',
    '--ft-theme-inquiries-page-bg: var(--ft-theme-page-bg);',
    '--ft-theme-order-detail-page-bg: var(--ft-theme-page-bg);',
    '--ft-theme-inquiry-intelligence-page-bg: var(--ft-theme-page-bg);',
    '--ft-theme-bulk-order-page-bg: var(--ft-theme-page-bg);',
    '--ft-theme-task-bg: var(--ft-theme-page-bg);',
] as $needle) {
    if (! str_contains($settings, $needle)) $failures[] = "plain-white page background contract changed: {$needle}";
}
foreach ([
    'resources/css/modules/orders/list.css' => 'var(--ft-theme-orders-list-page-bg)',
    'resources/css/modules/inquiries/core/inquiries-01.css' => 'var(--ft-theme-inquiries-page-bg)',
    'resources/css/modules/orders/detail/detail-01.css' => 'var(--ft-theme-order-detail-page-bg)',
    'resources/css/modules/reports/inquiry-intelligence.css' => 'var(--ft-theme-inquiry-intelligence-page-bg)',
    'resources/css/modules/work/my-work.css' => 'var(--ft-theme-task-bg)',
    'resources/css/modules/work/all-tasks.css' => 'var(--ft-theme-task-bg)',
] as $file => $needle) {
    if (! str_contains($read($file), $needle)) $failures[] = "screen background bypasses the centralized white canvas: {$file}";
}
if (str_contains($read('resources/css/modules/work/all-tasks.css'), 'body{background:var(--ft-theme-primary-soft)')) {
    $failures[] = 'All Task page is using the accent-soft token as a page background.';
}

// The application sans-serif family has one owner. Code/hex fields may still use monospace intentionally.
$foundationTokens = $read('resources/css/foundation/tokens.css');
if (! str_contains($foundationTokens, '--ft-font-family-sans: var(--ft-theme-font-family);')) {
    $failures[] = 'foundation typography does not delegate the application font family to theme settings.';
}
if (! str_contains($read('resources/theme/flowtrack/system.css'), 'font-family: var(--ft-theme-font-family);')) {
    $failures[] = 'system shell does not consume the centralized application font family.';
}
if (! str_contains($read('resources/theme/flowtrack/system.css'), 'font-size: var(--ft-theme-root-font-size);')) {
    $failures[] = 'html root does not consume the centralized typography scale control.';
}
if (! str_contains($read('resources/theme/flowtrack/system.css'), 'font-family: var(--ft-theme-font-family) !important;')) {
    $failures[] = 'form controls do not enforce the centralized application font family.';
}
if (! str_contains($read('resources/theme/flowtrack/system.css'), 'font-family: var(--ft-theme-font-family-mono) !important;')) {
    $failures[] = 'technical monospace content does not consume the centralized mono family.';
}

// All static CSS font sizes must be relative so the root typography control can scale the full system.
$fixedPxFontSizes = [];
foreach (['resources/css', 'resources/theme'] as $relative) {
    $base = $root.'/'.$relative;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'css') continue;
        $source = preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($file->getPathname())) ?? '';
        if (preg_match('/(?<![-\w])font-size\s*:[^;}{]*\d+(?:\.\d+)?px\b/i', $source)) {
            $fixedPxFontSizes[] = str_replace($root.'/', '', $file->getPathname());
        }
    }
}
if ($fixedPxFontSizes !== []) $failures[] = 'fixed px font sizes bypass centralized root typography scaling: '.implode(', ', array_unique($fixedPxFontSizes));
$fontFamilyViolations = [];
foreach (['resources/css', 'resources/views'] as $relative) {
    $base = $root.'/'.$relative;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (! $file->isFile()) continue;
        $name = $file->getFilename();
        if (! str_ends_with($name, '.css') && ! str_ends_with($name, '.blade.php')) continue;
        $source = preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($file->getPathname())) ?? '';
        if (preg_match('/(?:font-family|(?<!-)font)\s*:[^;}{]*\bInter\b/i', $source)) {
            $fontFamilyViolations[] = str_replace($root.'/', '', $file->getPathname());
        }
    }
}
if ($fontFamilyViolations !== []) $failures[] = 'application font family is duplicated outside settings.css: '.implode(', ', array_unique($fontFamilyViolations));

foreach (['resources/css/flowtrack.css', 'resources/css/legacy', 'resources/css/migration'] as $removed) {
    if (file_exists($root.'/'.$removed)) $failures[] = "removed CSS owner returned: {$removed}";
}

$vite = $read('vite.config.js');
$layout = $read('resources/views/layouts/app.blade.php');
$login = $read('resources/views/auth/login.blade.php');
if (! str_contains($vite, "'resources/theme/flowtrack/theme.css'")) $failures[] = 'Vite does not own authenticated theme.css.';
if (! str_contains($vite, "'resources/theme/flowtrack/core.css'")) $failures[] = 'Vite does not own theme core.css.';
if (! str_contains($layout, "@vite('resources/theme/flowtrack/theme.css')")) $failures[] = 'Authenticated layout does not load theme.css.';
if (! str_contains($login, "'resources/theme/flowtrack/core.css'")) $failures[] = 'Login does not load lightweight theme core.';

// Theme must be loaded after route/application CSS so its static values win the cascade.
$themePos = strpos($layout, "@vite('resources/theme/flowtrack/theme.css')");
$lastFeaturePos = strpos($layout, "@vite('resources/css/modules/clients/filters.css')");
if ($themePos === false || $lastFeaturePos === false || $themePos < $lastFeaturePos) $failures[] = 'Theme package is not loaded after feature CSS.';
$livewireStylesPos = strpos($layout, '@livewireStyles');
if ($themePos === false || $livewireStylesPos === false || $themePos < $livewireStylesPos) $failures[] = 'Theme package is not loaded after Livewire styles.';

if ($failures !== []) {
    fwrite(STDERR, "FlowTrack modular theme package FAIL\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "FlowTrack modular Dashboard theme package PASS\n";
echo " - single editable static theme owner: resources/theme/flowtrack/settings.css\n";
echo " - Dashboard management theme + sidebar migrated out of legacy compatibility\n";
echo " - official/module/Dashboard/sidebar token APIs bridge to one package\n";
echo " - all screen-level page backgrounds resolve to one centralized pure-white canvas\n";
echo " - application sans-serif + monospace families have one owner in settings.css\n";
echo " - all static CSS font sizes are relative to the centralized root font-size control\n";
echo " - raw static package colors outside settings.css: 0\n";
echo " - login uses lightweight core; authenticated shell uses full theme\n";
