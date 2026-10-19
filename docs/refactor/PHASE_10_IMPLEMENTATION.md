# Phase 10 - Upload and Document Security

## Outcome
Phase 10 moves new business-document writes behind a private, quarantine-first storage boundary while retaining dual-read compatibility for existing public/local document paths.

## Implemented boundaries
- `SecureDocumentStorage` owns quarantine, random physical naming, scanning, promotion, dual-read and physical deletion.
- `UploadSecurityService` owns extension/size/signature/ZIP checks and optional ClamAV execution.
- `StoredFileResponse` owns hardened streaming and forced-download behavior for EPS/ESP/AI/PostScript.
- Order, Inquiry, Product document and Finance attachment writers use the secure storage boundary.
- Authenticated rich-text images also use private storage.
- Existing public browser assets are intentionally not treated as sensitive business documents.

## Compatibility
Database path/name contracts are preserved. Existing files can still be read from configured legacy disks while migration is in progress. `flowtrack:migrate-private-documents` copies referenced objects to the private disk without deleting the source by default; `--delete-source` removes a verified legacy copy.

## Quarantine lifecycle
Files are stored in quarantine before inspection. A rejected file or scanner failure is never promoted and never linked to a business record. The scheduled quarantine purge removes stale pending objects after the configured retention period.

## No behavior redesign
Order/Inquiry document versioning, Finance permissions, Product-document permissions, activity logging and deletion decisions remain in their established application/service boundaries. Phase 10 changes storage and response security, not business workflow semantics.

## Release checks
Run `npm run quality:phase10`. In a dependency-complete environment also run the full Laravel tests and Vite build. Before deleting legacy production files, run the private-document migration without `--delete-source`, verify representative attachments, then run it again with `--delete-source`.
