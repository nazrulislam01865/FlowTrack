# Order Artwork upload throughput update (2026-09-03)

## Scope

Order Details -> Artwork only (`ART_PREPARE_UPLOAD` and `ART_SAMPLE_APPROVAL`, including selective revisions). Other uploads keep their existing behavior and limits.

## Changes

- Default chunk size increased from **5MB to 15MB**.
- The browser uploads **3 chunks concurrently per file**.
- Multiple selected files are still processed one file at a time, preventing concurrency from multiplying across a 10-file selection.
- The server accepts chunks in any order and records each chunk idempotently, so retries cannot double-count data.
- A shared browser `AbortController` stops sibling requests when one worker fails or the modal is closed.
- Completion verifies the exact expected chunk count, indexes, sizes, and total bytes before a staged upload can be marked complete.
- Chunk concurrency is server-configurable and clamped to 1-6 to protect PHP-FPM capacity.

## Defaults

```env
FLOWTRACK_ARTWORK_CHUNK_BYTES=15728640
FLOWTRACK_ARTWORK_CHUNK_CONCURRENCY=3
```

No database migration is required. Existing 400MB Artwork validation, permissions, quarantine scanning, document versioning, and non-Artwork upload behavior are unchanged.
