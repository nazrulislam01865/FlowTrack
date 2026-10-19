#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = realpath(__DIR__ . '/../..');
if ($root === false) {
    fwrite(STDERR, "Unable to resolve project root.\n");
    exit(2);
}

$baselinePath = $root . '/quality/phase8-application.json';
if (! is_file($baselinePath)) {
    fwrite(STDERR, "Missing quality/phase8-application.json.\n");
    exit(2);
}

$baseline = json_decode((string) file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR);
$phase10Path = $root . '/quality/phase10-document-security.json';
$phase10 = is_file($phase10Path)
    ? json_decode((string) file_get_contents($phase10Path), true, 512, JSON_THROW_ON_ERROR)
    : null;
$failures = [];

function p8Read(string $root, string $relative): string
{
    $path = $root . '/' . $relative;
    return is_file($path) ? (string) file_get_contents($path) : '';
}

function p8Lines(string $source): int
{
    return $source === '' ? 0 : substr_count($source, "\n") + 1;
}

$legacyMap = [
    'app/Services/LegacyJobService.php' => (string) ($baseline['legacy_job_service_hash'] ?? ''),
    'app/Services/LegacyInquiryService.php' => is_array($phase10)
        ? (string) ($phase10['legacy_inquiry_service_hash'] ?? ($baseline['legacy_inquiry_service_hash'] ?? ''))
        : (string) ($baseline['legacy_inquiry_service_hash'] ?? ''),
    'app/Services/LegacyDashboardService.php' => (string) ($baseline['legacy_dashboard_service_hash'] ?? ''),
];
foreach ($legacyMap as $relative => $expectedHash) {
    $path = $root . '/' . $relative;
    if (! is_file($path)) {
        $failures[] = "$relative is missing";
        continue;
    }
    if ($expectedHash === '' || ! hash_equals($expectedHash, hash_file('sha256', $path))) {
        $failures[] = "$relative changed; Phase 8 compatibility implementation must stay frozen until its capability is deliberately migrated";
    }
}

$facades = [
    'app/Services/JobService.php' => 'LegacyJobService',
    'app/Services/InquiryService.php' => 'LegacyInquiryService',
    'app/Services/DashboardService.php' => 'LegacyDashboardService',
];
$maxFacadeLines = (int) ($baseline['facade_max_lines'] ?? 40);
foreach ($facades as $relative => $legacyClass) {
    $source = p8Read($root, $relative);
    $lines = p8Lines($source);
    if ($source === '') {
        $failures[] = "$relative is missing";
        continue;
    }
    if ($lines > $maxFacadeLines) {
        $failures[] = "$relative grew beyond the Phase 8 compatibility-facade budget ({$lines} > {$maxFacadeLines})";
    }
    if (! str_contains($source, "extends {$legacyClass}")) {
        $failures[] = "$relative no longer delegates to {$legacyClass}";
    }
}

foreach ([
    ['key' => 'required_order_services', 'dir' => 'app/Services/Orders'],
    ['key' => 'required_inquiry_services', 'dir' => 'app/Services/Inquiries'],
    ['key' => 'required_dashboard_services', 'dir' => 'app/Services/Dashboard'],
] as $group) {
    foreach ($baseline[$group['key']] ?? [] as $service) {
        $relative = $group['dir'] . '/' . $service . '.php';
        $source = p8Read($root, $relative);
        if ($source === '') {
            $failures[] = "$relative is missing";
            continue;
        }
        if (preg_match('/\bLivewire\\\\|App\\\\Livewire\\\\/', $source)) {
            $failures[] = "$relative depends on Livewire; focused domain services must remain transport-independent";
        }
    }
}

foreach ($baseline['required_dtos'] ?? [] as $dto) {
    $relative = 'app/DTOs/' . $dto;
    $source = p8Read($root, $relative);
    if ($source === '') {
        $failures[] = "$relative is missing";
        continue;
    }
    if (! str_contains($source, 'final readonly class')) {
        $failures[] = "$relative must remain an immutable DTO";
    }
}

foreach ($baseline['required_dashboard_queries'] ?? [] as $query) {
    $relative = 'app/Queries/Dashboard/' . $query . '.php';
    $source = p8Read($root, $relative);
    if ($source === '') {
        $failures[] = "$relative is missing";
        continue;
    }
    if (! str_contains($source, 'AccessControlService')) {
        $failures[] = "$relative must authorize before returning dashboard read data";
    }
}

// Phase 8 transport code must not invoke the three giant compatibility facades directly.
$livewireDir = $root . '/app/Livewire';
$actionQueryDirs = [$root . '/app/Actions', $root . '/app/Queries'];
$transportSources = '';
foreach (array_merge([$livewireDir], $actionQueryDirs) as $dir) {
    if (! is_dir($dir)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $transportSources .= "\n" . (string) file_get_contents($file->getPathname());
        }
    }
}
foreach (['JobService', 'InquiryService', 'DashboardService'] as $service) {
    if (preg_match('/app\s*\(\s*' . preg_quote($service, '/') . '::class\s*\)\s*->/', $transportSources)) {
        $failures[] = "Transport code directly invokes {$service}; use a focused Action/Query/domain service boundary";
    }
}

// Actions/Queries may use compatibility constants, but not inject the giant facade as their dependency.
foreach ($actionQueryDirs as $dir) {
    if (! is_dir($dir)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') continue;
        $source = (string) file_get_contents($file->getPathname());
        $relative = str_replace($root . '/', '', $file->getPathname());
        if (preg_match('/use App\\\\Services\\\\(?:JobService|InquiryService|DashboardService);/', $source)) {
            $failures[] = "$relative imports a giant compatibility facade instead of a focused service";
        }
        if (preg_match('/\bLivewire\\\\|App\\\\Livewire\\\\/', $source)) {
            $failures[] = "$relative depends on Livewire; Actions/Queries must be transport-independent";
        }
    }
}

// The frozen legacy implementations must only be referenced from the compatibility facades/focused services.
$appDir = $root . '/app';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (! $file->isFile() || $file->getExtension() !== 'php') continue;
    $source = (string) file_get_contents($file->getPathname());
    if (! preg_match('/App\\\\Services\\\\Legacy(?:Job|Inquiry|Dashboard)Service|\bLegacy(?:Job|Inquiry|Dashboard)Service\b/', $source)) continue;
    $relative = str_replace($root . '/', '', $file->getPathname());
    $allowed = str_starts_with($relative, 'app/Services/Orders/')
        || str_starts_with($relative, 'app/Services/Inquiries/')
        || str_starts_with($relative, 'app/Services/Dashboard/')
        || in_array($relative, array_keys($facades), true)
        || in_array($relative, array_keys($legacyMap), true);
    if (! $allowed) {
        $failures[] = "$relative bypasses the Phase 8 compatibility/focused-service boundary";
    }
}

if (is_dir($root . '/app/Repositories')) {
    $failures[] = 'app/Repositories was introduced; Phase 8 explicitly avoids repository abstractions without a real substitution boundary';
}

foreach ([
    'tests/Feature/Phase8ApplicationBoundaryTest.php',
    'app/Actions/Dashboard/MarkDashboardMentionsRead.php',
] as $required) {
    if (! is_file($root . '/' . $required)) $failures[] = "$required is missing";
}

if ($failures !== []) {
    fwrite(STDERR, "Phase 8 application boundary FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, " - {$failure}\n");
    exit(1);
}

printf("Phase 8 application boundary PASS\n");
printf(" - JobService facade: %d lines\n", p8Lines(p8Read($root, 'app/Services/JobService.php')));
printf(" - InquiryService facade: %d lines\n", p8Lines(p8Read($root, 'app/Services/InquiryService.php')));
printf(" - DashboardService facade: %d lines\n", p8Lines(p8Read($root, 'app/Services/DashboardService.php')));
printf(" - focused services: %d\n", count($baseline['required_order_services'] ?? []) + count($baseline['required_inquiry_services'] ?? []) + count($baseline['required_dashboard_services'] ?? []));
printf(" - DTOs: %d\n", count($baseline['required_dtos'] ?? []));
printf(" - dashboard queries: %d\n", count($baseline['required_dashboard_queries'] ?? []));
