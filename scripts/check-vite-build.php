<?php

declare(strict_types=1);

$buildDirectory = $argv[1] ?? '';
$manifestPath = rtrim($buildDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'manifest.json';

if ($buildDirectory === '' || ! is_file($manifestPath)) {
    fwrite(STDERR, "Vite manifest is missing.\n");
    exit(1);
}

$manifest = json_decode((string) file_get_contents($manifestPath), true);
if (! is_array($manifest) || $manifest === []) {
    fwrite(STDERR, "Vite manifest is invalid.\n");
    exit(1);
}

foreach ($manifest as $entry) {
    if (! is_array($entry)) {
        continue;
    }

    foreach (array_merge([$entry['file'] ?? null], $entry['css'] ?? []) as $asset) {
        if (! is_string($asset) || $asset === '') {
            continue;
        }

        $assetPath = rtrim($buildDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$asset;
        if (! is_file($assetPath) || filesize($assetPath) === 0) {
            fwrite(STDERR, "Vite asset is missing: {$asset}\n");
            exit(1);
        }
    }
}

exit(0);
