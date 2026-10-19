# FlowTrack current executable state — Phase 15

This file distinguishes the current executable source from later target-state documentation.

## Current authoritative state

As of the Phase 15 snapshot:

- Laravel 13 + Livewire 4 business behavior and the existing modular-monolith deployment remain intact.
- Phase 0 architecture/quality budgets remain the regression baseline.
- Phase 1 owns static design tokens/global CSS; Phase 3 finalization has removed the former legacy compatibility boundary and monolithic stylesheet.
- Phase 2 owns the official reusable Blade/CSS component contracts.
- Phase 3 moved direct public CSS into managed Vite source. The finalization pass then removed `flowtrack.css`, the `legacy/` tree and the `migration/` tree, split the preserved source into bounded component/module owners, and added a 100 KB per-file ceiling. Phase 15 also removes the generated four-chunk delivery mechanism; `resources/css/app.css` is the authenticated Vite CSS root.
- Phase 4 owns the shared forms/filter/search/selection interaction architecture.
- `FilterOptionService::searchPage()` plus `FilterOptionPage` are the authoritative paged selector boundary.
- Remote selector pages are capped at 20 rows; selected IDs are separately bounded/resolved; incomplete non-empty search never returns unrelated fallback rows.
- `x-ui.search-select`, `x-ui.multi-select`, `x-ui.search-input`, `x-ui.filter-bar`, `x-ui.filter-chip`, `x-ui.filter-reset`, and `x-ui.date-range` are the official feature-facing contracts.
- Existing selector components remain only as explicit compatibility adapters where a dedicated migration has not yet been visually approved.
- Document Archive, Workflow Setup client selection, Product selected-client availability, User/Admin Department selection, Inquiries, Orders and client-order filters now exercise the shared architecture.
- Phase 5 decomposes the Orders UI/Livewire boundary while preserving the existing route and Livewire method contract.
- `App\Livewire\Jobs\Index` is now a compatibility coordinator/state owner; focused Order concerns own create, detail, products, tasks, documents, finance, activity, workflow and page-data orchestration.
- Touched Order write paths enter through transport-independent classes in `app/Actions/Orders`; visible Order/list reads enter through `app/Queries/Orders`.
- Core `JobService`, `TaskService`, `DocumentService` and `OrderFinanceService` behavior remains intact behind the Phase 5 boundaries; broader service decomposition remains Phase 8 work.
- Large stable Order Blade sections were split through inherited-state partials with exact pre/post source reconstruction checks.
- Phase 6 decomposes the Inquiries UI/Livewire boundary while preserving Inquiry-specific persistence and workflow semantics.
- `App\Livewire\Inquiries\Index` is now a compatibility coordinator/state owner; focused Inquiry concerns own list, create, products, detail, tasks, documents, activity/comments, workflow/status, final decision/conversion and page-data orchestration.
- Inquiry writes enter through transport-independent classes in `app/Actions/Inquiries`; permission-scoped list/detail/workflow reads enter through `app/Queries/Inquiries`.
- Inquiry Livewire/Blade no longer resolves `InquiryService` directly. The service remains unchanged behind Actions/Queries as the canonical authorization/transaction/audit compatibility boundary until Phase 8.
- Large stable Inquiry Blade regions are split into inherited-state sections and protected by exact reconstruction hashes.
- Inquiry-specific task/status/attention/conversion semantics remain separate from Order persistence; only existing shared UI primitives are reused.
- Phase 7 decomposes Master Data, Clients and the administration setup screens while preserving their public Livewire/route behavior.
- `App\Livewire\MasterData\Index` and `App\Livewire\Clients\Index` are now small compatibility coordinators composed from focused concerns.
- Client writes enter through `app/Actions/Clients`; Master Data writes/deletions touched by Phase 7 enter through `app/Actions/MasterData`.
- Workflow Setup, Task Pack Setup and Order Workflow Setup user-initiated writes enter through `app/Actions/Setup` while the existing services remain the canonical business-rule boundary until Phase 8.
- Reusable setup page/list/editor/safe-delete/color primitives live under `resources/views/components/setup` and use the existing approved CSS classes.
- Master Data runtime colors remain driven by `MasterColor` and the centralized dynamic-color design-system contract.
- Phase 8 establishes focused Order, Inquiry and Dashboard domain services, immutable DTO conventions and authorized Dashboard Queries. The old `JobService`, `InquiryService` and `DashboardService` names are thin compatibility facades over frozen `Legacy*Service` implementations while remaining callers are migrated incrementally.
- Phase 8 transport code no longer directly invokes those giant facades for the migrated Order/Inquiry/Dashboard paths, and no repository layer was introduced.
- Phase 9 replaces unrestricted model mass assignment across all application models with explicit `$fillable` allowlists; synchronized Workflow/Task Pack identity fields are narrowly allowlisted where the existing mirror/snapshot design requires them.
- Phase 9 adds AccessControlService-backed policies for Orders, Inquiries, Order/Inquiry tasks, Documents and Clients without changing AccessControlService semantics.
- `WorkspaceContext` is request-scoped and now owns active-workspace resolution for workspace-keyed records; `SetupContext` is retained as a compatibility adapter. No tenant columns were added to tables that do not already have them.
- Central `SecurityHeaders` middleware provides MIME/frame/referrer/permissions protection, CSP report-only and HTTPS-production-only HSTS.
- Phase 10 makes `flowtrack_private` authoritative for new sensitive business-document writes and introduces a quarantine-first upload lifecycle through `SecureDocumentStorage` and `UploadSecurityService`.
- Existing public/local document references remain dual-readable during rollout; `flowtrack:migrate-private-documents` copies referenced legacy objects to private storage and deletes legacy sources only when explicitly run with `--delete-source` after verification.
- `StoredFileResponse` is the hardened delivery boundary; EPS/ESP/AI/PostScript-like files are always downloads, ZIP archives are inspected without extraction, and optional ClamAV scanning can be enabled for production.
- Phase 11 inventories all 312 current syntactic application `->get()` occurrences and freezes the reviewed classification in `quality/phase11-query-inventory.json`. Reviewed operational list entry points are paginated or otherwise explicitly bounded.
- Phase 11 adds 12 composite indexes in one isolated migration for current Order/Task/Inquiry/Document/Notification/Activity/Master Data/Client query shapes while preserving hashes of all 100 pre-Phase-11 migrations.
- Local/testing environments enable non-throwing Eloquent lazy-loading detection. Runtime p95/query-plan acceptance remains a release-environment measurement using the existing performance monitor and `flowtrack:performance:explain`.
- Phase 12 decomposes the active Dashboard read path into focused Queries for summary, priority, attention, mentions, team performance, client portfolio, distributions, activity, catalogue readiness and reference data.
- `DashboardPrimaryQuery` and `DashboardSecondaryQuery` no longer call the legacy aggregate `primaryData()`/`secondaryData()` entry points.
- Team Performance and Inquiry Intelligence Livewire screens now enter through Query boundaries instead of resolving reporting services directly.
- `DashboardReadModelCache` owns short-lived safe-array caches with tag support, workspace/client versioning and per-user generation invalidation bridged through the compatibility `DashboardService::forget*` methods.
- Existing SQL aggregate implementations remain authoritative; no materialized tables were introduced because representative production measurements are not available in this archive.
- Phase 13 makes `resources/js/app.js` the authenticated composition root and moves browser ownership into `resources/js/core`, `resources/js/components` and `resources/js/features`.
- `core/navigation.js` owns the top-level Livewire SPA lifecycle with an idempotent bind guard; feature boot functions may run repeatedly without multiplying global navigation handlers.
- `core/realtime.js` owns the single Reverb WebSocket client, channel resubscription and deterministic exponential reconnect lifecycle; notification and workspace features consume that client while preserving their polling fallbacks.
- `core/events.js` owns shared Reverb/Livewire/browser event-name contracts.
- Blade/Alpine callers use the minimal `window.FlowTrack.ui.*` namespace. Phase 15 removes the deprecated broad `window.FlowTrack*` forwarding aliases and the compatibility bridge after call-site search proved active callers use the namespaced API.
- Eight unmanaged historical `public/js/flowtrack-*.js` source files are removed. Bulk Order Import no longer loads SheetJS from jsDelivr; the existing parser version is Vite-managed and route-loaded through the feature loader.
- Phase 14 adds an explicit horizontal-production profile: Redis-backed cache/session/queues, Redis-coordinated Reverb scaling, shared/object storage configuration, independent queue/Reverb/scheduler processes, stateless readiness checks, backup/restore commands and authenticated load-test scenarios.
- `FLOWTRACK_HORIZONTAL_SCALING` is the rollout/rollback switch. Single-node defaults remain compatible until the shared infrastructure is actually provisioned.
- `/up` remains process liveness while `/health/ready` validates database, cache, queue, configuration and the shared-storage sentinel without starting a user session.
- Phase 14 does not change business migrations, CSS, Blade UI or route/deep-link contracts. Runtime concurrency and restore-drill acceptance must still be executed on representative infrastructure.
- Phase 15 is the current release-hardening boundary. `.github/workflows/flowtrack-ci.yml` runs architecture/static gates, clean Composer/npm installs, Pint, PHPUnit, Vite/bundle budgets, dependency audits, optional authenticated visual/browser checks, and tag release reproducibility.
- `OperationsMetrics` owns bounded Redis-backed rolling telemetry for request latency/error rate, query time/slow queries, memory, cache hit rate, queue delay/failures and Reverb reconnect/errors; scheduled threshold evaluation logs `flowtrack.observability.alert`.
- Proven-dead assets are removed: the unused welcome view, duplicate management-theme source, broad JavaScript compatibility bridge/aliases, split CSS generator and generated `flowtrack-01..04` source chunks.
- Active `Legacy*Service` implementations remain as the machine-enforced backend exception set. CSS compatibility files are no longer active: `flowtrack.css`, `resources/css/legacy/`, and `resources/css/migration/` were removed and replaced by bounded component/module ownership guarded by `quality/css-finalization-manifest.json`.

## Quality source of truth

Run `npm run quality:phase15`. Do not re-baseline architecture, legacy CSS, selector, or test debt simply to make a regression pass.

Full PHPUnit, production Vite build and authenticated visual comparisons remain mandatory release checks in the dependency-complete development/deployment environment.

### Post-Phase-15 theme modularization

The current executable state remains Phase 15 plus an approved modular theme package and the completed Phase 3 CSS finalization. The Dashboard management visual language is the default static application theme. System colors, font family/scale, shell values and sidebar design are controlled from `resources/theme/flowtrack/settings.css`; all application CSS now lives under explicit component/module ownership.
