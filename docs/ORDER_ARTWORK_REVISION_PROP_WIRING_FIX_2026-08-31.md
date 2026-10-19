# Order Artwork Revision Prop Wiring Fix — 2026-08-31

## Problem
Opening an Artwork workflow action could trigger `Undefined variable $overviewTaskArtworkRevision` in `resources/views/components/jobs/detail-overview.blade.php`.

The selective artwork revision state was already calculated in `BuildsOrderPageData`, but it was not forwarded through the anonymous Blade component chain:

`livewire/jobs/index.blade.php` → `jobs.detail` → `jobs.detail-overview` → `order-detail.document-modal`.

## Fix
- Added `overviewTaskArtworkRevision` as an explicit prop on `jobs.detail`.
- Forwarded it from the Livewire Jobs view into `jobs.detail`.
- Added it as an explicit prop on `jobs.detail-overview`.
- Forwarded it into the task document modal as `artworkRevision`.
- Added safe empty-array defaults for normal non-revision uploads.
- Added regression assertions for the complete prop chain.

No database migration is required.
