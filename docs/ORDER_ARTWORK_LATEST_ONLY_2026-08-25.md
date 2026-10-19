# Order Details Artwork — Latest Document Only

## Requested behavior
In **Order Details → Artwork stage → Prepare & Upload Artwork**, the live task row should show only the latest artwork revision. Older/archived artwork versions must remain stored for history but must not be listed in the stage UI.

## File changed
`resources/views/components/jobs/order-detail/task-row.blade.php`

### 1. Artwork resource list
The Artwork task still resolves all versions so the latest version can be determined correctly, but `$resourceDocuments` is now restricted to the single latest artwork document:

```php
$resourceDocuments = $isArtworkUploadTask
    ? collect([$latestArtworkDocument])->filter()->values()
    : $taskDocuments
        ->reject(fn ($document) => $revisionReferenceDocumentIds->contains((int) $document->id))
        ->values();
```

This affects only the `ART_PREPARE_UPLOAD` task. Other task document behavior is unchanged.

### 2. Status/files summary
The Artwork task summary no longer displays an archived-count suffix. It now shows only the current version and its state:

```blade
@if($isArtworkUploadTask)
    · Version {{ max(1, (int) $latestTaskDocument->version) }} · Latest
@endif
```

### 3. Archived versions are not deleted
No records are deleted. Older versions remain available in the document/version-history mechanisms. This is only an Order Details presentation change.

## Test updated
`tests/Feature/OrderArtworkRevisionCommentDisplayTest.php` now checks that the live Artwork row uses only `$latestArtworkDocument` and no longer renders the archived-count text.

## Deployment
No migration and no frontend rebuild are required.

```bash
php artisan view:clear
php artisan optimize:clear
```
