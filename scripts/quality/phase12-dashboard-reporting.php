#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = realpath(__DIR__.'/../..');
if ($root === false) exit(2);
$failures = [];
$read = static fn (string $relative): string => is_file($root.'/'.$relative) ? (string) file_get_contents($root.'/'.$relative) : '';

$requiredQueries = [
    'DashboardSummaryQuery','DashboardPriorityWorkQuery','DashboardAttentionQuery','DashboardMentionsQuery',
    'DashboardTeamPerformanceQuery','DashboardClientPortfolioQuery','DashboardDistributionQuery',
    'DashboardActivityQuery','DashboardCatalogueReadinessQuery','DashboardReferenceQuery',
];
foreach ($requiredQueries as $query) {
    if (!is_file($root.'/app/Queries/Dashboard/'.$query.'.php')) $failures[] = 'Missing dashboard Query '.$query;
}
if (!is_file($root.'/app/Queries/Reports/InquiryIntelligenceReportQuery.php')) $failures[] = 'Missing Inquiry Intelligence report Query.';

$primary = $read('app/Queries/Dashboard/DashboardPrimaryQuery.php');
foreach (['DashboardSummaryQuery','DashboardPriorityWorkQuery','DashboardAttentionQuery','DashboardTeamPerformanceQuery','DashboardClientPortfolioQuery','DashboardDistributionQuery','DashboardActivityQuery','DashboardCatalogueReadinessQuery'] as $query) {
    if (!str_contains($primary, $query)) $failures[] = 'DashboardPrimaryQuery does not compose '.$query;
}
if (str_contains($primary, 'primaryData(')) $failures[] = 'DashboardPrimaryQuery still depends on the monolithic primaryData aggregate.';
if (!str_contains($primary, 'dashboardPeriod($actor, $filters->rangeDays)')) $failures[] = 'Global dashboard period is not passed into the focused team read model.';

$secondary = $read('app/Queries/Dashboard/DashboardSecondaryQuery.php');
if (str_contains($secondary, 'secondaryData(')) $failures[] = 'DashboardSecondaryQuery still depends on the monolithic secondaryData aggregate.';

$dashboardLivewire = $read('app/Livewire/Dashboard/Index.php');
$teamLivewire = $read('app/Livewire/TeamPerformance/Report.php');
$reportLivewire = $read('app/Livewire/Reports/Index.php');
if (!str_contains($dashboardLivewire, 'DashboardPrimaryQuery::class')) $failures[] = 'Dashboard Livewire does not use DashboardPrimaryQuery.';
if (str_contains($teamLivewire, 'DashboardReportingService::class')) $failures[] = 'Team Performance Livewire still resolves DashboardReportingService directly.';
if (!str_contains($teamLivewire, 'DashboardTeamPerformanceQuery::class')) $failures[] = 'Team Performance Livewire is missing focused Query boundary.';
if (str_contains($reportLivewire, 'InquiryIntelligenceService::class')) $failures[] = 'Inquiry Intelligence Livewire still resolves InquiryIntelligenceService directly.';
if (!str_contains($reportLivewire, 'InquiryIntelligenceReportQuery::class')) $failures[] = 'Inquiry Intelligence Livewire is missing report Query boundary.';

$cache = $read('app/Services/Dashboard/DashboardReadModelCache.php');
foreach (['dashboard_cache_seconds','Cache::supportsTags()','generationKey','WorkspaceRefreshService','ClientService'] as $needle) {
    if (!str_contains($cache, $needle)) $failures[] = 'Dashboard cache ownership is missing '.$needle;
}
$facade = $read('app/Services/DashboardService.php');
foreach (['forgetUser($user)','forgetMentions($user)'] as $needle) {
    if (!str_contains($facade, $needle)) $failures[] = 'Dashboard compatibility facade does not bridge cache invalidation: '.$needle;
}

$legacy = $read('app/Services/LegacyDashboardService.php');
foreach (["selectRaw('count(*) as active_jobs')", "groupBy('tasks.assignee_id')", "'jobs as active_jobs_count'", "selectRaw('COUNT(*) as inquiries_count')", "selectRaw('count(*) as aggregate')"] as $needle) {
    if (!str_contains($legacy, $needle)) $failures[] = 'Expected SQL aggregate evidence missing: '.$needle;
}

foreach (['quality/phase12-dashboard-reporting.json','docs/dashboard-read-models.md','docs/refactor/PHASE_12_IMPLEMENTATION.md','tests/Feature/Phase12DashboardReadModelArchitectureTest.php'] as $relative) {
    if (!is_file($root.'/'.$relative)) $failures[] = $relative.' is missing.';
}

// No Phase 12 schema/materialized-table change was required.
$phase12Migrations = glob($root.'/database/migrations/2026_08_22_19*.php') ?: [];
if ($phase12Migrations !== []) $failures[] = 'Unexpected Phase 12 migration/materialized table introduced.';

if ($failures) {
    fwrite(STDERR, "Phase 12 dashboard/reporting architecture FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, ' - '.$failure."\n");
    exit(1);
}

echo "Phase 12 dashboard/reporting architecture PASS\n";
echo " - focused dashboard Queries: ".count($requiredQueries)."\n";
echo " - active primary/secondary aggregate entry points: decomposed\n";
echo " - report Livewire direct service coupling: removed\n";
echo " - cache ownership/invalidation: explicit\n";
echo " - SQL aggregate strategy: preserved\n";
