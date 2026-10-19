# FlowTrack File Security Policy

## Scope
This policy applies to business documents and authenticated attachment content managed by FlowTrack. Public UI assets such as branding images, profile images, client logos, product images, and product option images remain public because they are intentionally rendered by the browser.

## Storage ownership
- `flowtrack_private` is the authoritative disk for new business documents.
- `flowtrack_quarantine` is the temporary, non-public disk used before a file is accepted.
- `public` and `local` may remain configured temporarily as legacy read sources during migration only.
- No public storage symlink is defined for `flowtrack_private` or `flowtrack_quarantine`.

New uploads must not be written directly to a normal workflow location. The upload path is:

1. generate a random physical filename while retaining the original filename as application metadata;
2. write the bytes to `flowtrack_quarantine`;
3. validate extension, size, basic file signature and ZIP safety limits;
4. run the configured malware scanner;
5. copy/stream the accepted object to `flowtrack_private`;
6. delete the quarantine object only after the private object exists;
7. persist/link the attachment to its business record only after successful promotion.

Rejected or scan-failed files remain outside normal workflows and are never linked to a business record.

## Malware scanning
`FLOWTRACK_MALWARE_SCANNER=basic` enables the built-in validation layer. It rejects executable/script formats, executable signatures, suspicious script markers, unsafe ZIP metadata and signature/type mismatches where reliable.

For production enterprise deployment, configure:

```text
FLOWTRACK_MALWARE_SCANNER=clamav
FLOWTRACK_CLAMAV_BINARY=clamdscan
```

A ClamAV scanner error fails closed: the upload remains quarantined and is not promoted.

## High-risk active formats
EPS, ESP, AI, CDR and PostScript-like files are treated as untrusted design/active content. They may be stored when allowed by the business workflow, but `StoredFileResponse` always returns them as downloads with `application/octet-stream`; callers cannot force them inline.

## ZIP policy
FlowTrack never automatically extracts user ZIP archives. ZIP validation inspects metadata only and enforces entry-count, total-uncompressed-size, compression-ratio and path-safety limits. ZIP64 archives are rejected by the fallback parser when server ZIP support is unavailable rather than being interpreted unsafely.

## Authorized delivery
Business documents are served through authenticated, permission-aware controllers or `StoredFileResponse`. The response adds `nosniff`, private/no-store cache control and a restrictive content security policy. Direct public URLs are not the document-access mechanism.

Signed object-storage URLs are not currently required because the application streams files after authorization. If object storage is introduced later, signed URLs must be short-lived and issued only after the same server-side authorization check; objects must never become public to avoid authorization.

## Legacy document migration
The application supports dual-read during migration: private storage is checked first, followed by configured legacy disks. This is a compatibility boundary, not the permanent security state.

Recommended production sequence:

```bash
php artisan flowtrack:migrate-private-documents
```

Review the command result and verify representative Order, Inquiry, Product and Finance attachments through their normal authorized screens. The first run copies and verifies objects but keeps the source files.

After verification, run:

```bash
php artisan flowtrack:migrate-private-documents --delete-source
```

Legacy source files are deleted only after the private copy is confirmed. Keep `FLOWTRACK_DOCUMENT_DISK=flowtrack_private` as the authoritative disk. The migration command is idempotent and can be re-run.

## Retention and deletion
- Accepted business documents follow the lifecycle of their owning database record and existing FlowTrack deletion semantics.
- Physical deletion uses `SecureDocumentStorage`, which removes private and any remaining legacy copies only when the owning application operation decides the attachment may be deleted.
- Quarantine files are purged independently by `flowtrack:purge-document-quarantine`; the default retention is 72 hours.
- Upload promotion, quarantine and physical deletion are logged with FlowTrack security/document events.
- Existing database audit/activity behavior remains authoritative for business-record creation, versioning and deletion.

## Rollback
During rollout, keep legacy read disks available until representative documents have been verified. If a storage rollout must be reversed before legacy deletion, restore the previous disk configuration while retaining authenticated controller delivery. After `--delete-source`, rollback requires restoring the legacy objects from backup; the private copies remain authoritative.
