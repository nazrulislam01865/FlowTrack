#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$output = null;
foreach ($argv as $arg) if (str_starts_with($arg, '--write=')) $output = substr($arg, 8);

$treeHash = static function (string $relative) use ($root): ?string {
    $dir = $root.'/'.$relative;
    if (! is_dir($dir)) return null;
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) if ($file->isFile()) $files[] = $file->getPathname();
    sort($files, SORT_STRING);
    $ctx = hash_init('sha256');
    foreach ($files as $file) {
        $rel = str_replace('\\', '/', substr($file, strlen($root) + 1));
        hash_update($ctx, $rel."\0".hash_file('sha256', $file, true));
    }
    return hash_final($ctx);
};

$manifest = [
    'schema' => 1,
    'generated_at' => gmdate('c'),
    'php' => PHP_VERSION,
    'composer_lock_sha256' => is_file($root.'/composer.lock') ? hash_file('sha256', $root.'/composer.lock') : null,
    'package_lock_sha256' => is_file($root.'/package-lock.json') ? hash_file('sha256', $root.'/package-lock.json') : null,
    'vite_manifest_sha256' => is_file($root.'/public/build/manifest.json') ? hash_file('sha256', $root.'/public/build/manifest.json') : null,
    'migration_tree_sha256' => $treeHash('database/migrations'),
    'app_tree_sha256' => $treeHash('app'),
    'resources_tree_sha256' => $treeHash('resources'),
];
$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
if ($output !== null && $output !== '') file_put_contents($root.'/'.$output, $json);
echo $json;
