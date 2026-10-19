# Priority 5 and 6 Performance Optimization - 2026-09-01

## Scope

This change deliberately implements only two low-risk performance improvements:

1. Nginx gzip compression for text-based responses.
2. A 10-minute cache for stable Orders stage-definition metadata.

No Order, Task, payment, document, artwork, permission or stage-count state is cached by this change.

## Priority 5 - gzip

`deploy/nginx-performance-snippet.conf.example` now enables gzip with compression level 6, a 1 KB minimum response size, `Vary: Accept-Encoding`, and the text MIME types used by FlowTrack. The existing immutable policy remains scoped to `/build/assets/`.

The snippet must be included in the production HTTPS `server` block. Validate with `nginx -t` before `systemctl reload nginx`. Do not restart the server for this change.

## Priority 6 - Orders stage-definition cache

`OrderListPrototypeService` now caches only the preferred active Order workflow's scalar presentation metadata:

- phase id
- phase name
- phase short name
- phase sequence
- phase color

The cache key is workspace-scoped and uses a 10-minute TTL. The payload is stored as scalar arrays rather than Eloquent models. Cache data is validated before use so a stale/incompatible payload is discarded and rebuilt.

### Data that remains live

The following continue to query current authorized data on every relevant request:

- stage counts
- Order status
- current tasks and task status
- assignees
- payments/invoices
- documents
- artwork approval/revision state
- permission-scoped Order visibility

### Invalidation

The stage-definition cache is explicitly forgotten whenever `WorkflowService` already invalidates workflow metadata. The dedicated seven-stage Order workflow save path also clears it after its phase transaction so the final workflow definition becomes visible immediately after setup changes.

## Validation performed

- PHP syntax validation for all modified PHP files.
- Static regression invariants confirm no live Order query appears inside the cached stage-definition resolver.
- Nginx syntax validation passed with the project snippet.
- A real built CSS asset was served through Nginx with `Accept-Encoding: gzip`; the response returned `Content-Encoding: gzip`, `Vary: Accept-Encoding`, and the one-year immutable asset cache policy.
- `/build/manifest.json` remained outside the immutable cache policy.
