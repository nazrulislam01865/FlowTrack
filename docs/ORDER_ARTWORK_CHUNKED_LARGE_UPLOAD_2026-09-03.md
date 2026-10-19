# Order Artwork large-file upload reliability fix (2026-09-03)

## Problem

Artwork uploads on **Order Details -> Artwork**, especially selective replacement fields such as
`overviewTaskRevisionUpload.<document-id>`, were sent through Livewire as one temporary upload request.
A 100-400MB request can spend a long time in transit and be terminated by PHP-FPM, Nginx, a proxy,
or a temporary network interruption. Livewire then surfaces the generic message:

`The overviewTaskRevisionUpload.<id> failed to upload.`

A second server-side limit also existed: the secure document scanner defaulted to 50MB even though
Artwork validation allowed 400MB.

## Fix

Artwork files now use an authenticated chunk transport:

1. The browser validates the normal business extension and 400MB per-file limit.
2. The server creates a user/task-scoped staging token.
3. The browser sends the file in 15MB chunks, up to 3 chunks concurrently, with transient-error retries and visible overall progress.
4. The server validates task access on every chunk and stores chunks on the quarantine disk.
5. Livewire receives only the completed staging token, never the 400MB payload.
6. When the user clicks the Artwork save action, the server reconstructs the file and sends it through the existing
   `DocumentService -> SecureDocumentStorage -> UploadSecurityService` quarantine/security path.
7. Staged chunks and reconstruction files are deleted after successful persistence. Cancelled/expired staging is cleaned up.

This is applied to the Artwork upload actions `ART_PREPARE_UPLOAD` and `ART_SAMPLE_APPROVAL`, including selective
Artwork revisions. Non-Artwork upload fields keep the previous Livewire transport and 20MB business limit.

## Security and limits

- Artwork per-file limit: **400MB**.
- Chunk request size: **15MB** by default (configurable, clamped to 1-18MB).
- Per-file upload concurrency: **3 chunks** by default (configurable, clamped to 1-6).
- Staging tokens are UUIDs and bound to the authenticated user and exact task.
- Selective revision tokens are also bound to the exact source Artwork document.
- Normal secure-storage max remains unchanged for non-Artwork uploads.
- Artwork explicitly receives the 400MB secure-storage allowance.
- ClamAV receives a larger timeout only for files above the normal secure-storage limit.
- Existing extension, signature, ZIP-safety, executable-content, malware, and quarantine checks remain in place.

## Configuration

Optional environment overrides:

```env
FLOWTRACK_ARTWORK_CHUNK_BYTES=15728640
FLOWTRACK_ARTWORK_CHUNK_CONCURRENCY=3
FLOWTRACK_ARTWORK_CHUNK_RETENTION_HOURS=6
FLOWTRACK_ARTWORK_PERSISTENCE_TIMEOUT=900
FLOWTRACK_ARTWORK_SCAN_TIMEOUT=300
```

No database migration is required.

## DigitalOcean / Nginx

The 15MB chunk endpoint remains below the existing 20MB standard attachment request ceiling while reducing round trips. The final save can still
legitimately spend time reconstructing/scanning a 400MB file, so production PHP/Nginx timeouts must not terminate that
server-side persistence request. `deploy/nginx-artwork-upload-snippet.conf.example` contains the recommended server-block
settings if the current virtual host is stricter.

After deployment:

```bash
npm ci
npm run build
php artisan optimize:clear
```

If the prebuilt `public/build` directory from this release is deployed, rebuilding on the server is optional.
