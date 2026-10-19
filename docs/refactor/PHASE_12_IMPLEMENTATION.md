# Phase 12 - Dashboard and Reporting Read-Model Architecture

Phase 12 replaces the active dashboard's single aggregate entry point with focused, authorized Query contracts while preserving the approved UI, routes, filters and business semantics.

## Delivered

- Focused dashboard Query classes for summary, priority work, attention, mentions, team performance, client portfolio, distributions, activity, catalogue readiness and reference options.
- `DashboardPrimaryQuery` composes those section Queries instead of calling `primaryData()`.
- `DashboardSecondaryQuery` composes focused Queries/section methods instead of calling `secondaryData()`.
- Team Performance Livewire now enters through `DashboardTeamPerformanceQuery`.
- Inquiry Intelligence Livewire now enters through `InquiryIntelligenceReportQuery`.
- `DashboardReadModelCache` owns short-lived cache keys, tag support and invalidation generations.
- Existing `DashboardService` remains a compatibility facade and bridges existing write invalidations into the Phase 12 cache owner.

## SQL aggregate reuse

The refactor deliberately retains proven SQL aggregate semantics: KPI `COUNT/SUM`, grouped task status counts, grouped assignee counts, Client Portfolio `withCount`/grouped inquiry statistics, and Catalogue Readiness counts. It does not replace them with PHP collection aggregation.

## Deferred

- Materialized/summary tables: deferred until measured production volume justifies them.
- Additional panel lazy-loading: not introduced because Tagged Comments already has an isolated lazy component and splitting currently visible primary panels would change the approved dashboard loading behavior without measured UX benefit.
- Legacy implementation deletion: deferred until runtime PHPUnit/visual/performance validation in the dependency-complete environment.
