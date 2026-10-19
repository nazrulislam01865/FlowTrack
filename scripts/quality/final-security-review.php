#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failures = [];
$read = static fn (string $rel): string => is_file($root.'/'.$rel) ? (string) file_get_contents($root.'/'.$rel) : '';

if (is_file($root.'/.env')) $failures[] = '.env must not be present in the release source archive.';
if (! str_contains($read('bootstrap/app.php'), 'SecurityHeaders::class')) $failures[] = 'SecurityHeaders middleware is not registered.';
if (! str_contains($read('config/filesystems.php'), "'flowtrack_private'")) $failures[] = 'private business-document disk missing.';
if (! str_contains($read('app/Support/StoredFileResponse.php'), "'attachment'")) $failures[] = 'hardened stored-file response no longer contains attachment handling.';
if (preg_match('/protected\s+\$guarded\s*=\s*\[\s*\]\s*;/', implode("\n", array_map('file_get_contents', glob($root.'/app/Models/*.php') ?: [])))) $failures[] = 'unrestricted Eloquent guarded=[] returned.';
if (preg_match('/window\.FlowTrack[A-Z][A-Za-z0-9_]*/', $read('resources/js/core/browser-api.js'))) $failures[] = 'deprecated broad browser global returned.';
$package = json_decode($read('package.json'), true, 512, JSON_THROW_ON_ERROR);
if (($package['devDependencies']['xlsx'] ?? null) !== 'https://cdn.sheetjs.com/xlsx-0.20.3/xlsx-0.20.3.tgz') $failures[] = 'stale/vulnerable npm SheetJS dependency returned.';

if ($failures) {
    fwrite(STDERR, "Final security source review FAIL\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}
echo "Final security source review PASS\n";
