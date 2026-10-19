# Order Details Artwork Popup Balanced Size Fix — 2026-08-22

## Issue
The Artwork preview/handoff modal was forced to a 1160px desktop width and up to 620px preview height. On wide but short desktop screens this made the dialog feel oversized and dominate the page.

## Fix
- Reduced Artwork preview modal maximum width to 980px.
- Added a viewport-aware maximum height cap.
- Reduced preview canvas height to a maximum of 500px / 52vh.
- Tightened header, body, footer, and action button spacing.
- Kept the two-column landscape preview layout.
- Kept Review Artwork, Send Artwork to Order Team, and Confirm Client ERP Upload consistent.
- Kept revision/comment dialogs on the normal compact modal shell.

No workflow, task, document, revision, or phase logic was changed.
