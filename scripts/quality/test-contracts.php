#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = realpath(__DIR__.'/../..');
if ($root === false) {
    fwrite(STDERR, "Unable to resolve project root.\n");
    exit(2);
}

function tcRead(string $root, string $relative): string
{
    $path = $root.'/'.$relative;

    return is_file($path) ? (string) file_get_contents($path) : '';
}

$failures = [];

$factory = tcRead($root, 'database/factories/UserFactory.php');
foreach ([
    "'is_super_admin' => false",
    "'is_active' => true",
    "'locale' => 'en'",
] as $snippet) {
    if (! str_contains($factory, $snippet)) {
        $failures[] = "UserFactory is missing FlowTrack default: $snippet";
    }
}

$profile = tcRead($root, 'app/Livewire/Profile/Index.php');
if (! str_contains($profile, "filled(\$user->locale) ? (string) \$user->locale : 'en'")) {
    $failures[] = 'Profile locale must tolerate legacy/null locale values.';
}

$cacheMiddleware = tcRead($root, 'app/Http/Middleware/PreventDynamicPageCaching.php');
foreach (['CACHE_MANAGED_ASSET_ROUTES', "'profile-images.show'", "'branding-assets.show'", 'routeIs(self::CACHE_MANAGED_ASSET_ROUTES)'] as $snippet) {
    if (! str_contains($cacheMiddleware, $snippet)) {
        $failures[] = "PreventDynamicPageCaching missing binary-cache ownership contract: $snippet";
    }
}

$featureRoot = $root.'/tests/Feature';
$interpolationPattern = '/assertString(?:Not)?ContainsString\(\"(?:[^\"\\\\]|\\\\.)*(?<!\\\\)\$[A-Za-z_]/';
if (is_dir($featureRoot)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($featureRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        if (preg_match($interpolationPattern, $source, $match, PREG_OFFSET_CAPTURE)) {
            $offset = $match[0][1] ?? 0;
            $line = substr_count(substr($source, 0, $offset), "\n") + 1;
            $relative = str_replace($root.'/', '', $file->getPathname());
            $failures[] = "$relative:$line contains an unescaped PHP variable inside a source-string assertion";
        }
    }
}

printf("Test/fixture contract gate\n");
printf("%-42s %s\n", 'UserFactory active/default contract', str_contains($factory, "'is_active' => true") ? 'PASS' : 'FAIL');
printf("%-42s %s\n", 'Profile null-locale fallback', str_contains($profile, "filled(\$user->locale)") ? 'PASS' : 'FAIL');
printf("%-42s %s\n", 'Binary cache policy ownership', str_contains($cacheMiddleware, 'CACHE_MANAGED_ASSET_ROUTES') ? 'PASS' : 'FAIL');
printf("%-42s %s\n", 'Unsafe source-assertion interpolation', empty(array_filter($failures, fn (string $failure): bool => str_contains($failure, 'source-string assertion'))) ? '0' : 'FOUND');

if ($failures !== []) {
    fwrite(STDERR, "\nFAIL: test/fixture contracts regressed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - $failure\n");
    }
    exit(1);
}

fwrite(STDOUT, "\nPASS: test fixtures mirror production defaults and source-string assertions are interpolation-safe.\n");
