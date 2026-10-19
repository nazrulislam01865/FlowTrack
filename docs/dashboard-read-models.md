# Dashboard and Reporting Read Models

## Ownership

Phase 12 keeps `DashboardService` as a compatibility facade, but active Dashboard/Team Report screens enter through focused Queries under `app/Queries/Dashboard`. Inquiry Intelligence enters through `app/Queries/Reports/InquiryIntelligenceReportQuery`.

| Section | Query owner | Cache policy | Invalidation |
| --- | --- | --- | --- |
| KPI summary | `DashboardSummaryQuery` | short-lived array cache | workspace version, client lifecycle, user generation |
| Priority work | `DashboardPriorityWorkQuery` | uncached bounded operational rows | realtime source of truth |
| Needs attention | `DashboardAttentionQuery` | uncached bounded operational rows | realtime source of truth |
| Mentions | `DashboardMentionsQuery` | uncached | notification writes clear mention/dashboard generations |
| Team performance | `DashboardTeamPerformanceQuery` | uncached grouped SQL read model | current report filters |
| Client portfolio | `DashboardClientPortfolioQuery` | uncached aggregate read model | current period/team/client filters |
| Flow + task status | `DashboardDistributionQuery` | short-lived array cache | workspace version, client lifecycle, user generation |
| Recent activity | `DashboardActivityQuery` | uncached bounded rows | current dashboard filters |
| Catalogue readiness | `DashboardCatalogueReadinessQuery` | short-lived array cache | workspace version, client lifecycle, user generation |

The cache TTL uses `performance.dashboard_cache_seconds` (45 seconds by default). Cache stores that support tags receive dashboard/user tags; all stores additionally use explicit version/generation keying. `DashboardService::forget()` and `forgetMentions()` bump the per-user generation, so existing write paths invalidate Phase 12 caches without changing their callers.

## Filter contract

`DashboardFilterData` remains the single dashboard filter DTO. Client, Team/Department and period are passed to every section where they are semantically relevant. Search and tab selection remain presentation filters where the existing UI intentionally filters an already bounded result set. Mentions apply period/client/team/search directly before SQL `LIMIT`.

## Aggregate strategy

Phase 12 reuses the existing SQL aggregate implementations rather than changing their semantics during the architecture migration. Summary metrics use SQL `COUNT/SUM`, team performance uses grouped counts by assignee, Client Portfolio uses `withCount` plus grouped Inquiry statistics, task status uses grouped status counts, and Catalogue Readiness uses SQL counts. No materialized tables were introduced because representative production volume/EXPLAIN/p95 evidence is not available in the supplied archive.

## Rollback

`LegacyDashboardService` remains intact as the compatibility implementation. A Query can be reverted to its existing focused adapter without changing routes or Blade contracts.
