# Order Details Artwork Preview Target Size — 2026-08-22

## Scope

Adjusted only the Artwork preview/handoff dialogs in Order Details so they use the wide landscape proportions shown in the approved fourth reference image.

Affected main-step dialogs:

- Review Artwork
- Send Artwork to Order Team
- Confirm Client ERP Upload

## Behaviour

- Desktop width is capped at 1160px with viewport-safe side spacing.
- The artwork preview and metadata remain in a two-column landscape layout.
- The preview area can grow to 620px high while preserving the file/image aspect ratio.
- Header and action footer stay visible; the body scrolls on shorter screens.
- Long filenames and version history remain contained without squeezing the modal.
- Tablet/mobile layouts remain responsive.

## Important safeguard

Artwork revision/comment dialogs are **not** given the wide preview class. A revision step opened from an Artwork task therefore uses the normal compact modal instead of inheriting the large landscape size.

No workflow, revision-cycle, task progression, document-versioning, validation, permission or phase logic was changed.
