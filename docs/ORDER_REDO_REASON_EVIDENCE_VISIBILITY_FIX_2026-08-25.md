# FlowTrack Order Redo - Reason Editor, Latest Artwork Evidence, and Redo Visibility Fix

Date: 2026-08-25

This update fixes three Redo issues:

1. The rich-text Issue Description editor could render but could not receive typing/caret input.
2. Redo Evidence was showing arbitrary/latest task files (including archived artwork), instead of only the current latest artwork.
3. The Redo tab/section was visible before any Redo or discount adjustment had actually been initiated.

## 1. Fix Issue Description rich-text typing

File:

`resources/views/components/jobs/order-detail/redo-modal.blade.php`

### Why it was broken

The rich-text textarea was wrapped by a `<label>`. FlowTrack's shared rich-text JavaScript hides that textarea and inserts a `contenteditable` editor beside it. Because the generated editor remained inside the wrapping label, clicking the editor could trigger the label's implicit activation behavior and send focus back to the hidden textarea. The visible editor then looked active but could not retain the caret for typing.

### Replace the rich-text field wrapper

Replace the wrapping `<label>` field with this non-label container:

```blade
<div class="ft-redo-field wide ft-mention-host">
    <span id="redo-issue-description-label">Issue description *</span>

    <textarea
        class="ft-mention-input"
        data-rich-text
        rows="5"
        wire:model="redoIssueDescription"
        autocomplete="off"
        aria-labelledby="redo-issue-description-label"
        data-mention-users="{{ json_encode($redoMentionUsers->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
        placeholder="Describe the customer/QC issue. Type @ to mention someone, paste an image/screenshot, or use the editor tools."
    ></textarea>

    @error('redoIssueDescription')
        <small class="validation-error">{{ $message }}</small>
    @enderror
</div>
```

No new editor library is required. This continues to use FlowTrack's existing `resources/js/components/rich-text.js`, mention search, image paste/upload and screenshot capture.

## 2. Evidence now shows only the latest artwork

File:

`app/Services/OrderRedoService.php`

Add the Document import:

```php
use App\Models\Document;
```

Replace `evidenceLabels()` with:

```php
/**
 * Return only the CURRENT/LATEST artwork proof for the Redo issue step.
 *
 * @return array<int,string>
 */
public function evidenceLabels(FlowJob $order): array
{
    $artworkTask = Task::query()
        ->where('flow_job_id', $order->id)
        ->with('setupTemplate:id,automation_key')
        ->get(['id', 'task_pack_task_id', 'title'])
        ->filter(
            fn (Task $task): bool =>
                app(OrderWorkflowActionService::class)->automationKey($task) === 'ART_PREPARE_UPLOAD'
        )
        ->sortByDesc('id')
        ->first();

    if (! $artworkTask) {
        return [];
    }

    $latestArtwork = Document::query()
        ->where('flow_job_id', $order->id)
        ->where('task_id', $artworkTask->id)
        ->orderByDesc('version')
        ->orderByDesc('created_at')
        ->orderByDesc('id')
        ->first(['id', 'name', 'version']);

    $name = trim((string) ($latestArtwork?->name ?? ''));

    return $name !== '' ? [$name] : [];
}
```

This deliberately follows the active `ART_PREPARE_UPLOAD` task and takes one newest artwork version only. It does not mix in Purchase Order, QC, sample, or other task documents and does not list older artwork versions.

The evidence display in `redo-modal.blade.php` now uses:

```blade
@if($evidence->isNotEmpty())
    <b>{{ $evidence->first() }}</b>
    <small>Latest artwork attached to the source Order. Archived artwork versions are not shown here.</small>
@else
    <b>No latest artwork available</b>
    <small>Upload artwork on the source Order before using it as Redo evidence.</small>
@endif
```

## 3. Hide the Redo tab/section until Redo is actually initiated

File:

`resources/views/components/jobs/order-detail/tabs.blade.php`

Wrap the Redo tab in `hasRedo`:

```blade
@if((bool) ($redoContext['hasRedo'] ?? false))
    <button
        type="button"
        class="page-tab {{ $detailTab === 'redo' ? 'active' : '' }}"
        wire:click="setDetailTab('redo')"
    >
        Redo
        <span class="ft-redo-tab-count">
            {{ (int) ($redoContext['redoCount'] ?? 0) }}
        </span>
    </button>
@endif
```

The Initiate Redo button remains available in the Order header. Only the Redo details tab/section is hidden until there is an actual Redo or discount adjustment record.

## 4. Protect direct `?tab=redo` URLs

File:

`app/Livewire/Jobs/Concerns/BuildsOrderPageData.php`

Before loading the selected tab:

```php
$preloadedRedoContext = null;

if ($this->detailTab === 'redo') {
    $preloadedRedoContext = app(OrderRedoService::class)->context(
        $selected,
        $user
    );

    if (! (bool) ($preloadedRedoContext['hasRedo'] ?? false)) {
        $this->detailTab = 'overview';
    }
}

$orderQuery->loadTab($selected, $user, $this->detailTab);
```

Later reuse it:

```php
$orderRedoContext = $preloadedRedoContext
    ?? app(OrderRedoService::class)->context($selected, $user);
```

This prevents `/orders/...?...tab=redo` from opening a Redo surface on an Order that has never had Redo initiated.

## 5. Extra view-level Redo guard

File:

`resources/views/components/jobs/detail.blade.php`

Use:

```blade
@elseif($detailTab==='redo' && (bool) ($orderRedoContext['hasRedo'] ?? false))
    <x-jobs.order-detail.redo-panel
        :job="$job"
        :context="$orderRedoContext"
    />
```

`resources/views/components/jobs/order-detail/redo-panel.blade.php` also no longer contains the previous "No redo has been created yet" empty Redo section. The panel itself renders only when a real Redo/discount record exists.

## Files changed

- `app/Services/OrderRedoService.php`
- `app/Livewire/Jobs/Concerns/BuildsOrderPageData.php`
- `resources/views/components/jobs/order-detail/redo-modal.blade.php`
- `resources/views/components/jobs/order-detail/tabs.blade.php`
- `resources/views/components/jobs/order-detail/redo-panel.blade.php`
- `resources/views/components/jobs/detail.blade.php`
- `tests/Feature/OrderRedoImplementationTest.php`

## After copying the files

Run:

```bash
php artisan view:clear
php artisan optimize:clear
```

No database migration and no frontend rebuild are required for this update.
