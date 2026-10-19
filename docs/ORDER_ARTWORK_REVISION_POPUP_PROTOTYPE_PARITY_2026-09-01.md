# Request Artwork Revision popup prototype parity — 2026-09-01

The Request Artwork Revision dialog now keeps the supplied prototype layout and spacing. The only additional block is **Which artwork needs revision?**, placed immediately before **Required change**.

The selector remains multi-select and uses the shared file-type badge component. Existing revision request validation, attachment uploads, rich-text value submission, selective artwork replacement, and revision history behavior are unchanged.

The source stylesheet and the currently generated Orders CSS bundle are both updated so the corrected compact layout is visible immediately when deploying the archive's existing `public/build` directory. A future normal Vite build will reproduce the same source styling.
