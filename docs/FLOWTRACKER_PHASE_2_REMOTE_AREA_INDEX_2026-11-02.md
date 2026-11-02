# FlowTracker Phase 2: Remote Area compiled lookup (local-first)

## Scope and current status

This change affects only `MasterDataService::remoteAreaForPostalCode()` and Remote Area master-data revision tracking. It **does not** change Order Details design, prices, invoices, workflow processing, supplier sync, Livewire events, or PHP-FPM. Phase 1 diagnostics are left untouched.

**Code status:** prepared and syntactically checked. Feature OFF by default. The supplied archive has no `vendor/`, Redis server or database, so Laravel/Redis integration tests and production workload verification are **not** claimed as completed. Do not enable in production until local/staging differential tests pass.

**Why the small migration is required:** Redis can be unavailable when an edit commits. A durable DB revision lets every index read detect a stale generation even if an invalidation notification is lost. The new `remote_area_lookup_versions` table stores only `workspace_id` and `revision`, not customer addresses or order data.

## Algorithm

* Existing public method unchanged; flag OFF runs the original matcher.
* Flag ON: read current workspace revision from DB; verify Redis pointer and manifest; HGET normalized exact key (exact always wins); on miss, use one lexicographic predecessor query in the range ZSET for the matching L/D shape; then fetch only the winning, active `MasterRecord` in the same workspace.
* Range compilation uses an ordinal-priority sweep line. Overlapping ranges choose the earliest original active-record order. Inclusive bounds, leading zeros, invalid descending ranges, `metadata.postal_code` legacy fallbacks and punctuation-only exact codes are preserved.
* CLI and queue workers build indexes; **HTTP requests never build them**. Incomplete/stale/unavailable index => legacy fallback. Revisions increment after every Eloquent Remote Area change and are committed alongside ordinary `MasterDataService` updates.
* One published pointer per workspace; immutable generation keys. Current generation has no expiry. Retired generations expire after the configured overlap period. A Redis build lock prevents concurrent builders.
* On change: the DB version invalidates the published pointer immediately; after commit, a background job queues rebuild (except on sync queue), with a five-minute scheduler recovery sweep.

## Changed files

1. `app/Services/MasterDataService.php` - feature-gated lookup and existing matcher fallback; atomic Remote Area toggle/delete.
2. `app/Services/RemoteAreas/PostalRangeCompiler.php` - pure ordered range interval compiler and binary-search verifier.
3. `app/Services/RemoteAreas/RemoteAreaLookupService.php` - Redis reader, version check, builder, health validation.
4. `app/Observers/RemoteAreaLookupVersionObserver.php` - durable revision updates and postcommit cache invalidation/rebuild dispatch.
5. `app/Providers/AppServiceProvider.php` - observer registration.
6. `app/Jobs/RebuildRemoteAreaIndex.php` - asynchronous rebuild.
7. `app/Console/Commands/BuildRemoteAreaLookup.php` - manual warm command.
8. `app/Console/Commands/MaintainRemoteAreaLookup.php` - scheduler recovery command.
9. `routes/console.php` - enabled-only recovery schedule.
10. `config/remote_area_lookup.php` - disabled-by-default settings.
11. `database/migrations/2026_11_02_190000_create_remote_area_lookup_versions_table.php` - durable version table.
12. `tests/Unit/PostalRangeCompilerTest.php` - exact/shape/overlap/random differential unit tests.
13. `tests/Feature/RemoteAreaCompiledLookupTest.php` - Redis/DB opt-in integration test.
14. This document.

## Local installation and verification (macOS)

1. Back up changed files or create a Git branch. Extract the *changed-files-only ZIP* over the correct local project root. Review with `git diff --check` and `git status`.
2. Ensure your **local** database is selected and has a backup. `php artisan migrate --path=database/migrations/2026_11_02_190000_create_remote_area_lookup_versions_table.php`.
3. Confirm Redis (phpredis recommended by the project's `REDIS_CLIENT` default): `php -m | grep -i redis` and `redis-cli ping`. If Redis or PhpRedis is missing, do not enable the feature; the legacy matcher stays available.
4. Run `php artisan test --filter=PostalRangeCompilerTest` and `php artisan test --filter=RemoteAreaMasterDataTest`. For an isolated Redis test database only, set `FLOWTRACK_REMOTE_AREA_INDEX_TESTS=1` and run `php artisan test --filter=RemoteAreaCompiledLookupTest`.
5. In local `.env`, set `FLOWTRACK_REMOTE_AREA_INDEX_ENABLED=true`. Run `php artisan config:clear`. Then run `php artisan flowtrack:remote-area-index 1` (replace 1 with the actual local workspace ID). It should print `published: true`, with `records`, `exact`, `segments` and checksum.
6. Check health with `php artisan tinker --execute='var_export(app(\\App\\Services\\RemoteAreas\\RemoteAreaLookupService::class)->ready(1));'`. It must show `true`.
7. Compare old/new matched record IDs and exact surcharge amounts across representative postal codes (including 00000, ranges, overlaps, alphanumeric, empty/invalid, from-only legacy). Use different workspaces if applicable. Save screen/invoice snapshots.
8. Edit, deactivate and delete a disposable Remote Area on local. Verify that revision change immediately makes `ready()` false (so no stale Redis match is used). Run `php artisan flowtrack:remote-area-index 1`, verify ready() returns true and the match now reflects the edit.
9. Re-run Phase 1 with a **new run ID**, cold and warm runs separately. Compare P50/P95, PHP CPU, SQL time, query count, record/surcharge correctness. Phase 2 acceptance target from playbook: warm matcher P95 <=30ms and meaningful CPU reduction on production-like data; not a guarantee.
10. To revert *immediately*, set `FLOWTRACK_REMOTE_AREA_INDEX_ENABLED=false`, clear/rebuild config as appropriate. No database rollback or data deletion is needed to return to original matching.

## Production hold points / risks

* DO NOT turn on this feature in production until the Redis integration test passes with your actual Redis client (PhpRedis/Predis) and same DB engine; smoke-test queue worker and scheduler availability.
* This patch cannot guarantee correct invalidation for **raw SQL or query-builder bulk updates to matching fields** that bypass Eloquent events. The code audit of this archive identified standard Remote Area saves/toggles/deletes in `MasterDataService`; any external data imports or direct SQL maintenance must increment the revision (or trigger an approved rebuild with revision bump).
* Existing database order is `sort_order`, then `name`, with unspecified ties. The new compiler adds `id` as a deterministic final tie-breaker. Compare actual tied overlapping rows with the legacy matcher before enablement.
* Redis failures or a stale/missing index return to the original potentially expensive matcher: this protects pricing correctness but may temporarily restore CPU pressure. Alert on fallback rate, health and rebuild failures.
* Local Phase 1 found a separate 600- to 1,082-query Workflow problem. This patch intentionally does **not** alter that issue (Phase 4/5).

## Exit gate

**Not achieved until proven**: same-record ID and surcharge for all selected old/new fixtures; exact matches and overlapping intervals; cross-workspace isolation; modifications invalidate the index; failed Redis/build falls back; queue/schedule rebuild; no 65,897 model hydration in warm index path; measured warm P95 and CPU improvement under matched workloads; feature flag rollback tested; no UI/order/supplier regression.
