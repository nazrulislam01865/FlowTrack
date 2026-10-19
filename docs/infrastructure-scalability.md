# FlowTrack Phase 14 infrastructure and horizontal scalability

## Target topology

```text
Internet
  |
Load balancer / Nginx
  |
  +-- FlowTrack web node A -- PHP-FPM
  +-- FlowTrack web node B -- PHP-FPM
  +-- FlowTrack web node N -- PHP-FPM
             |
             +-- Shared MySQL/MariaDB
             +-- Shared Redis/Tair/Valkey
             +-- Shared filesystem or private object storage
             +-- Independent queue workers
             +-- Reverb processes coordinated through Redis
             +-- Scheduler (one logical schedule via onOneServer locks)
```

No application request should require a particular web node.

## Safe rollout switch

Phase 14 is configuration-gated. Existing single-node deployments stay compatible until:

```env
FLOWTRACK_HORIZONTAL_SCALING=true
```

When enabled, the default cache/session/queue backends become Redis and Reverb scaling defaults on. `deploy/env.horizontal.example` contains the complete production profile. Rollback is configuration-level: remove the node from the load balancer, set the profile back to false/database drivers if required, clear config cache, and restart workers/Reverb.

## Redis ownership

Use a managed HA Redis/Tair/Valkey service reachable from every web/worker/Reverb node. The reference profile separates workloads:

- DB 0 generic locks/default
- DB 1 cache
- DB 2 sessions
- DB 3 queues
- DB 4 Reverb scaling

If the provider supports Redis Cluster with DB 0 only, use separate endpoints/key prefixes rather than logical databases. Redis is private infrastructure and must not be exposed publicly.

## Shared files

The zero-new-dependency production path is a shared mounted filesystem. Mount the same durable volume on every node and set:

```env
FLOWTRACK_PUBLIC_STORAGE_PATH=/srv/flowtrack-shared/public
FLOWTRACK_PRIVATE_STORAGE_PATH=/srv/flowtrack-shared/private
FLOWTRACK_QUARANTINE_STORAGE_PATH=/srv/flowtrack-shared/quarantine
```

This preserves existing disk names and routes while removing local-node ownership for product images, client logos, profile images, branding, private documents and quarantine files.

Before switching paths on a live installation, copy the existing data while the old node is authoritative:

```bash
rsync -a --delete-delay storage/app/public/ /srv/flowtrack-shared/public/
rsync -a --delete-delay storage/app/flowtrack-private/ /srv/flowtrack-shared/private/
rsync -a --delete-delay storage/app/flowtrack-quarantine/ /srv/flowtrack-shared/quarantine/
```

Run a final short maintenance-window sync before enabling the new paths. Do not delete old storage until representative images/documents have been verified through FlowTrack.

### Optional object storage

`flowtrack_object` and `flowtrack_object_quarantine` are S3-compatible private disk definitions. They can target AWS S3, Alibaba OSS through an S3-compatible endpoint, MinIO or another compatible provider. Install `league/flysystem-aws-s3-v3` before selecting those disks. Phase 10 secure uploads can now scan remote quarantine objects by streaming a temporary inspection copy and never link that temporary file to business data.

## Queue topology

`deploy/flowtrack-workers-horizontal.conf.example` creates independent Supervisor pools for realtime, notifications, outbound email and default work. Equivalent systemd units are under `deploy/systemd/`; the templated queue unit can be instantiated for the `emails` queue as well.

Owned queue jobs have explicit retries/backoff/timeouts. Realtime jobs also use unique dispatch identities; duplicate realtime signals are harmless because the browser re-reads database state/notification IDs. Queue delay above the configured threshold is logged as `flowtrack.queue.delay`; failed jobs and queue-depth alarms are logged centrally.

The scheduler runs Laravel `queue:monitor` every minute when enabled.

## Reverb

Run one Reverb process per desired socket node, separately supervised from PHP-FPM. Each process can remain bound to loopback when Nginx on the same node proxies `/app` and `/apps`. Set `REVERB_SCALING_ENABLED=true`; the Reverb servers coordinate through the shared Redis endpoint.

## Health checks

- `/up`: liveness — Laravel/PHP process can answer.
- `/health/ready`: readiness — configuration, DB, cache, queue and shared/private storage sentinel.

Before adding a node to the load balancer:

```bash
php artisan flowtrack:infrastructure:check --prepare-storage
curl -fsS https://node-or-service/health/ready
```

Do not expose detailed readiness diagnostics publicly. `FLOWTRACK_HEALTH_EXPOSE_DETAILS` defaults false.

## Database connection planning

Run:

```bash
php artisan flowtrack:infrastructure:check
```

The command reports expected app process count and a minimum connection-capacity planning figure. MySQL `max_connections` remains a database-server/managed-service setting. `deploy/mysql-performance.cnf.example` contains a conservative self-managed example and preserves the Phase 11 150 ms slow-query threshold.

## Production deployment order

1. Provision shared Redis and shared storage/object storage.
2. Copy existing files to the shared location; keep legacy files intact.
3. Install code/dependencies and build assets.
4. Set the horizontal environment profile on one canary node.
5. `php artisan optimize:clear && php artisan migrate --force`.
6. `php artisan flowtrack:infrastructure:check --prepare-storage`.
7. Start Supervisor/systemd queue, scheduler and Reverb services.
8. Verify `/up`, `/health/ready`, queues and Reverb.
9. Add the canary node to the load balancer and run smoke tests.
10. Add remaining nodes one at a time.
11. Run the Phase 14 expected/headroom load tests before declaring runtime acceptance.
