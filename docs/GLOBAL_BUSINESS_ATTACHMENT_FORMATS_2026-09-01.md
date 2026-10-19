# Global business attachment formats — 2026-09-01

FlowTrack now uses `App\Support\AttachmentUpload` as the single source of truth for normal business-document attachments across Orders, Inquiries, Tasks, Document Archive, RFQ supplier uploads, artwork revision evidence, finance supporting documents and product supporting documents.

Supported attachment extensions:

- PDF
- Word: DOC, DOCX
- Excel: XLS, XLSX
- Images: JPG, JPEG, PNG, WEBP, GIF, ICO
- Archives: ZIP
- Text/data: TXT, CSV
- Artwork/design: AI, EPS, ESP, CDR

Special-purpose inputs remain intentionally restricted to their purpose. Profile/client/product/branding image fields still accept image formats only, and Bulk Order Import still accepts spreadsheet/CSV inputs only.

Security behavior is unchanged: business attachments are quarantined, checked for size/type/signature, scanned with the configured malware scanner, and only then promoted to private storage. ZIP archives are inspected but never automatically extracted. AI/EPS/ESP/CDR are treated as untrusted design formats and are forced to download instead of being rendered inline.
