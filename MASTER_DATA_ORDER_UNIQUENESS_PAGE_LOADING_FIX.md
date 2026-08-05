# Master Data Order, Uniqueness and Page-by-Page Loading Fix

## Master Data document order

- Document Categories now display an **Order** column in Master Data.
- Add/Edit Document Category uses a required **Order** field starting at `1`.
- New Document Categories automatically receive the next available order.
- Duplicate document order numbers are rejected in the UI and backend.
- Existing document-category order values are normalized to `1..N` by migration while preserving their current sequence.
- Fresh seed data also starts document-category order at `1`.

Migration:

```bash
php artisan migrate
```

## Master Data uniqueness

- Every Master Data section now rejects duplicate record names within the same workspace and section.
- Names are trimmed and repeated whitespace is normalized before save.
- Case-only duplicates are rejected by the model/backend guard.
- The API returns a clear validation message for duplicates.
- Codes remain automatically generated and already retain their existing unique constraint.

Examples that are treated as duplicates in the same section:

- `Air Freight`
- `air freight`
- `  Air   Freight  `

The same name may still exist in a different Master Data section because those are different entity types.

## Page-by-page loading

The page-scoped/lazy data architecture was still present, but My Work had previously been configured with `allPages: true`, which bypassed incremental loading.

This update restores true page-by-page loading:

- Login remains shell-only and does not preload operational datasets.
- Each page requests only the data/configuration it needs.
- My Work loads its first Task page for initial render.
- More My Work Tasks load only when the user reaches the end of the board.
- My Work no longer silently chains through every remaining page after render.
- Server-side My Work totals keep the sidebar badge and quick-filter counts accurate even when only part of the Task list is loaded.
- A top page progress indicator is shown while a new page/module/data segment is loading.
- No sidebar button spinner and no full-screen navigation flash were reintroduced.

## Main files changed

- `app/Http/Controllers/FlowTrack/Api/ConfigurationController.php`
- `app/Modules/OrderFlow/Data/Models/MasterRecord.php`
- `app/Modules/OrderFlow/Business/Services/OperationalMetricsService.php`
- `database/migrations/2026_08_03_170000_normalize_document_category_order.php`
- `database/seeders/FlowTrack/FlowTrackSeeder.php`
- `resources/js/flowtrack/modules/configuration.js`
- `resources/js/flowtrack/modules/core.js`
- `resources/js/flowtrack/modules/job-shared.js`
- `resources/js/flowtrack/modules/operations.js`
- `resources/js/flowtrack/loader.js`
- generated `public/flowtrack/*` runtime assets
