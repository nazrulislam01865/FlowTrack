#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = realpath(__DIR__ . '/../..');
if ($root === false) {
    fwrite(STDERR, "Unable to resolve project root.\n");
    exit(2);
}

$baselinePath = $root . '/quality/phase9-security.json';
if (! is_file($baselinePath)) {
    fwrite(STDERR, "Missing quality/phase9-security.json.\n");
    exit(2);
}
$baseline = json_decode((string) file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR);
$failures = [];

function p9Read(string $root, string $relative): string
{
    $path = $root . '/' . $relative;
    return is_file($path) ? (string) file_get_contents($path) : '';
}

function p9TreeHash(string $root, string $relativeDir, string $suffix): string
{
    $dir = $root . '/' . $relativeDir;
    $files = [];
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), $suffix)) $files[] = $file->getPathname();
        }
    }
    sort($files);
    $context = hash_init('sha256');
    foreach ($files as $file) {
        hash_update($context, str_replace($root . '/', '', $file));
        hash_update($context, "\0");
        hash_update($context, (string) file_get_contents($file));
        hash_update($context, "\0");
    }
    return hash_final($context);
}

function p9Fillable(string $source): array
{
    if (! preg_match('/protected\s+\$fillable\s*=\s*\[(.*?)\];/s', $source, $match)) return [];
    preg_match_all('/[\'\"]([^\'\"]+)[\'\"]/', $match[1], $keys);
    return array_values(array_unique($keys[1] ?? []));
}

$modelFiles = glob($root . '/app/Models/*.php') ?: [];
sort($modelFiles);
if (count($modelFiles) !== (int) ($baseline['model_count'] ?? 0)) {
    $failures[] = sprintf('Expected %d application models, found %d', (int) ($baseline['model_count'] ?? 0), count($modelFiles));
}

$modelFillables = [];
foreach ($modelFiles as $file) {
    $source = (string) file_get_contents($file);
    $relative = str_replace($root . '/', '', $file);
    if (preg_match('/protected\s+\$guarded\b/', $source)) {
        $failures[] = "$relative still declares \$guarded; Phase 9 requires explicit writable fields";
    }
    $fillable = p9Fillable($source);
    if ($fillable === []) {
        $failures[] = "$relative has no explicit non-empty \$fillable policy";
    }
    if (preg_match('/class\s+([A-Za-z_][A-Za-z0-9_]*)\s+extends/', $source, $match)) {
        $modelFillables[$match[1]] = $fillable;
    }
}

foreach ($baseline['required_fillable_fields'] ?? [] as $model => $requiredKeys) {
    foreach ($requiredKeys as $key) {
        if (! in_array($key, $modelFillables[$model] ?? [], true)) {
            $failures[] = "$model must explicitly allow {$key}; a current application write path depends on it";
        }
    }
}

$workspace = p9Read($root, 'app/Services/WorkspaceContext.php');
$setupContext = p9Read($root, 'app/Services/SetupContext.php');
$provider = p9Read($root, 'app/Providers/AppServiceProvider.php');
if ($workspace === '') {
    $failures[] = 'app/Services/WorkspaceContext.php is missing';
} else {
    foreach (['function set(', 'function id(', 'function scope(', 'function contains(', 'function assertModel('] as $needle) {
        if (! str_contains($workspace, $needle)) $failures[] = "WorkspaceContext is missing {$needle}";
    }
}
if (! str_contains($setupContext, 'WorkspaceContext') || ! str_contains($setupContext, '$this->workspace->id(')) {
    $failures[] = 'SetupContext no longer delegates workspace resolution to WorkspaceContext';
}
if (! str_contains($provider, 'scoped(WorkspaceContext::class)')) {
    $failures[] = 'WorkspaceContext is not request-scoped in AppServiceProvider';
}

foreach ($baseline['required_policies'] ?? [] as $policy) {
    $relative = 'app/Policies/' . $policy . '.php';
    $source = p9Read($root, $relative);
    if ($source === '') {
        $failures[] = "$relative is missing";
        continue;
    }
    if (! str_contains($source, 'AccessControlService')) {
        $failures[] = "$relative must delegate dynamic RBAC/scope decisions to AccessControlService";
    }
    if (! str_contains($provider, "Gate::policy(") || ! str_contains($provider, $policy . '::class')) {
        $failures[] = "$policy is not registered through Gate::policy";
    }
}

$headers = p9Read($root, 'app/Http/Middleware/SecurityHeaders.php');
$bootstrap = p9Read($root, 'bootstrap/app.php');
foreach ([
    'X-Content-Type-Options',
    'X-Frame-Options',
    'Referrer-Policy',
    'Permissions-Policy',
    'Content-Security-Policy-Report-Only',
] as $header) {
    if (! str_contains($headers, $header)) $failures[] = "SecurityHeaders is missing {$header}";
}
if (! str_contains($headers, "environment('production')") || ! str_contains($headers, 'isSecure()') || ! str_contains($headers, 'Strict-Transport-Security')) {
    $failures[] = 'HSTS must remain restricted to secure production requests';
}
if (! str_contains($bootstrap, 'SecurityHeaders::class')) {
    $failures[] = 'SecurityHeaders middleware is not registered in the web middleware stack';
}

$accessPath = $root . '/app/Services/AccessControlService.php';
if (! is_file($accessPath) || ! hash_equals((string) ($baseline['access_control_hash'] ?? ''), hash_file('sha256', $accessPath))) {
    $failures[] = 'AccessControlService semantics changed during Phase 9; this hardening phase must preserve the existing RBAC engine';
}
$migrationSnapshotPath = $root . '/quality/pre-phase11-migration-hashes.json';
if (is_file($migrationSnapshotPath)) {
    $migrationSnapshot = json_decode((string) file_get_contents($migrationSnapshotPath), true, 512, JSON_THROW_ON_ERROR);
    foreach (($migrationSnapshot['files'] ?? []) as $filename => $hash) {
        $path = $root . '/database/migrations/' . $filename;
        if (! is_file($path) || ! hash_equals((string) $hash, hash_file('sha256', $path))) {
            $failures[] = 'Pre-Phase-11 migration changed: ' . $filename;
        }
    }
} elseif (! hash_equals((string) ($baseline['migration_tree_hash'] ?? ''), p9TreeHash($root, 'database/migrations', '.php'))) {
    $failures[] = 'database/migrations changed during Phase 9; workspace hardening must not silently add tenant columns in this rollout';
}
if (! is_file($root . '/quality/phase15-release-hardening.json') && ! hash_equals((string) ($baseline['css_tree_hash'] ?? ''), p9TreeHash($root, 'resources/css', '.css'))) {
    $failures[] = 'resources/css changed during Phase 9; security hardening must not redesign the UI';
}
$routePath = $root . '/routes/web.php';
if (! is_file($routePath) || ! hash_equals((string) ($baseline['routes_web_hash'] ?? ''), hash_file('sha256', $routePath))) {
    $failures[] = 'routes/web.php changed during Phase 9; existing route/deep-link behavior must remain stable';
}

foreach ([
    'tests/Feature/Phase9SecurityArchitectureTest.php',
    'tests/Feature/Phase9WorkspaceIsolationTest.php',
] as $required) {
    if (! is_file($root . '/' . $required)) $failures[] = "$required is missing";
}

// Ensure the workspace-aware Inquiry policy denies records outside the active request workspace before applying RBAC scope.
$inquiryPolicy = p9Read($root, 'app/Policies/InquiryPolicy.php');
if (! str_contains($inquiryPolicy, 'WorkspaceContext') || ! str_contains($inquiryPolicy, 'workspace->contains')) {
    $failures[] = 'InquiryPolicy must reject cross-workspace records before RBAC hydration';
}

if ($failures !== []) {
    fwrite(STDERR, "Phase 9 security architecture FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, " - {$failure}\n");
    exit(1);
}

printf("Phase 9 security architecture PASS\n");
printf(" - application models with explicit fillable policies: %d\n", count($modelFiles));
printf(" - unrestricted guarded models: 0\n");
printf(" - registered policies: %d\n", count($baseline['required_policies'] ?? []));
printf(" - WorkspaceContext: request-scoped\n");
printf(" - CSP mode: report-only\n");
printf(" - AccessControlService: frozen\n");
printf(" - CSS/routes: unchanged; pre-Phase-11 migrations: hash-frozen\n");
