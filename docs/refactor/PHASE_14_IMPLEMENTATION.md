# Phase 14 implementation — infrastructure and horizontal scalability

## Scope

Phase 14 removes runtime assumptions that a request must return to one FlowTrack web node. It does not change business workflows or UI semantics.

## Production Redis profile

`FLOWTRACK_HORIZONTAL_SCALING=true` is the explicit rollout switch. In that profile, cache, sessions and queues default to Redis and Reverb scaling defaults on. Redis responsibilities are separated into cache/session/queue/Reverb logical databases in `config/database.php` and `deploy/env.horizontal.example`.

Single-node compatibility remains the default so a code deployment cannot silently require Redis before infrastructure is provisioned.

## Shared storage

The existing `public`, `flowtrack_private` and `flowtrack_quarantine` roots are environment-configurable. A shared mounted volume can therefore preserve existing disk names and application routes while making product/profile/client/branding media and private business documents available from every node.

Optional S3-compatible `flowtrack_object` and `flowtrack_object_quarantine` disks are defined for deployments that install the Flysystem S3 adapter. `SecureDocumentStorage` can inspect remote quarantined objects through a short-lived local inspection copy, preserving Phase 10 malware/signature checks.

## Workers, Reverb and scheduler

Supervisor examples provide separate Redis worker pools for `realtime`, `notifications`, `emails` and `default`; Reverb and the scheduler remain separate long-running processes. Equivalent systemd examples are included.

Owned realtime queue jobs now expose explicit timeout/failure behavior, queue-delay telemetry and unique dispatch identities. Laravel queue failures and queue-depth alerts are logged centrally. The scheduler runs `queue:monitor` when enabled.

## Health checks

`/up` remains Laravel process liveness. `/health/ready` is a stateless readiness endpoint that validates the horizontal configuration plus database, cache, queue and shared/private storage sentinel. Detailed dependency errors are hidden by default.

`php artisan flowtrack:infrastructure:check --prepare-storage` is the deployment/canary verification command.

## Database operations

`DB_CONNECT_TIMEOUT` bounds connection establishment. `flowtrack:infrastructure:check` reports process-based connection-capacity planning. A self-managed MySQL slow-query/max-connection example is provided without changing database business schema.

`flowtrack:database:backup` creates compressed consistent MySQL/MariaDB backups plus SHA-256 sidecars. `flowtrack:database:restore` verifies the sidecar when present and requires `--force`. Automated backups are opt-in and use scheduler `onOneServer()` semantics.

## Load testing

`tests/Load/phase14-flowtrack.k6.js` provides smoke, expected and headroom authenticated scenarios. It respects FlowTrack's single-active-session rule by requiring a distinct account per VU. Thresholds match the existing performance budgets: <1% request errors, >99% checks, p95 <500 ms for standard pages and <1000 ms for the Dashboard/heavy read.

The source environment cannot execute representative concurrency because it does not include a running production-like MySQL/Redis/Reverb topology or load-test user pool. Runtime acceptance therefore remains an explicit deployment gate rather than a fabricated pass.

## Rollback

1. Remove affected node(s) from the load balancer.
2. Stop horizontal queue/Reverb/scheduler programs.
3. Restore the previous environment values (`FLOWTRACK_HORIZONTAL_SCALING=false`, database cache/session/queue if needed).
4. Keep shared/object copies intact; never delete the original storage during the first cutover.
5. `php artisan optimize:clear && php artisan optimize`.
6. Restart the single-node worker/Reverb definitions and verify `/up` plus application smoke tests.

No data migration is required merely to toggle the infrastructure profile.
