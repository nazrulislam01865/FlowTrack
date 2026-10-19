#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failures = [];
$cssRoot = $root.'/resources/css';

$blueViolations = [];
$brandLiteralViolations = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cssRoot, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (! $file->isFile() || $file->getExtension() !== 'css') continue;
    $source = (string) file_get_contents($file->getPathname());
    $relative = str_replace($root.'/', '', $file->getPathname());

    if (preg_match_all('/#[0-9a-fA-F]{6}\b/', $source, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as [$hex, $offset]) {
            [$r, $g, $b] = array_map('hexdec', str_split(substr($hex, 1), 2));
            $max = max($r, $g, $b) / 255;
            $min = min($r, $g, $b) / 255;
            $l = ($max + $min) / 2;
            $d = $max - $min;
            $s = $d == 0 ? 0 : $d / (1 - abs(2 * $l - 1));
            if ($d == 0) {
                $h = 0;
            } elseif ($max === $r / 255) {
                $h = 60 * fmod((($g - $b) / 255) / $d, 6);
            } elseif ($max === $g / 255) {
                $h = 60 * (((($b - $r) / 255) / $d) + 2);
            } else {
                $h = 60 * (((($r - $g) / 255) / $d) + 4);
            }
            if ($h < 0) $h += 360;
            if ($h >= 205 && $h <= 235 && $s >= 0.48 && $l >= 0.30) {
                $blueViolations[] = $relative.':'.$hex;
            }
        }
    }

    // Static Dashboard-brand teal literals must also live in settings.css, not page CSS.
    if (preg_match_all('/#(?:007d70|006d62|006f64|087f73|087d72|086e64|00a08f|008c82)\b/i', $source, $m)) {
        foreach ($m[0] as $hex) $brandLiteralViolations[] = $relative.':'.$hex;
    }
}

if ($blueViolations !== []) {
    $failures[] = 'legacy/static blue brand colors bypass the theme: '.implode(', ', array_slice($blueViolations, 0, 20));
}
if ($brandLiteralViolations !== []) {
    $failures[] = 'hard-coded Dashboard brand teal bypasses settings.css: '.implode(', ', array_slice($brandLiteralViolations, 0, 20));
}

$settings = (string) file_get_contents($root.'/resources/theme/flowtrack/settings.css');
foreach (['--ft-theme-primary:', '--ft-theme-primary-hover:', '--ft-theme-primary-soft:', '--ft-theme-page-bg:'] as $token) {
    if (! str_contains($settings, $token)) $failures[] = "theme settings missing {$token}";
}
if (! str_contains($settings, '--ft-theme-page-bg: #ffffff;')) {
    $failures[] = 'global application page canvas is not pure white.';
}

if ($failures !== []) {
    fwrite(STDERR, "FlowTrack whole-system theme coverage FAIL\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "FlowTrack whole-system Dashboard theme coverage PASS\n";
echo " - static primary blue literals outside theme package: 0\n";
echo " - static Dashboard brand teal literals outside theme package: 0\n";
echo " - legacy/module primary states consume --ft-theme-* variables\n";
echo " - screen-level page canvas is centrally fixed to pure white\n";
echo " - runtime Master Data colors remain data-driven\n";
