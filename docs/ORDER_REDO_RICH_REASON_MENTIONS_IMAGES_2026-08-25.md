# Order Redo — Rich Issue Reason, Image Paste and @Mentions

Date: 2026-08-25

## Goal

Upgrade **Initiate Redo → Step 1 → Issue description** from a plain textarea to FlowTrack's existing shared rich-text editor so users can:

- format text (bold, italic, underline, bullet/numbered lists),
- paste screenshots/images directly with Ctrl/Cmd+V,
- use the existing Capture and Image buttons,
- type `@` and select any active FlowTrack user,
- store the reason safely using the existing rich-text sanitizer,
- notify mentioned users,
- render the stored reason, mentions and images safely in the Redo detail panel.

No new database migration is required. `order_redos.issue_description` is already a `TEXT` column, and FlowTrack's `RichTextService` caps rich content at 60,000 stored bytes.

## 1. `resources/views/components/jobs/detail.blade.php`

The existing `mentionUsers` collection is now passed into the Redo modal:

```blade
<x-jobs.order-detail.redo-modal
    :job="$job"
    :context="$orderRedoContext"
    :form="$orderRedoForm"
    :mention-users="$mentionUsers"
/>
```

## 2. `resources/views/components/jobs/order-detail/redo-modal.blade.php`

### Props

```blade
@props(['job', 'context' => [], 'form' => [], 'mentionUsers' => collect()])
```

The active-user mention list is normalized once:

```blade
$redoMentionUsers = collect($mentionUsers)->values();
```

### Issue description field

Replace the old plain textarea with:

```blade
<label class="ft-redo-field wide ft-mention-host">
    <span>Issue description *</span>
    <textarea
        class="ft-mention-input"
        data-rich-text
        rows="5"
        wire:model="redoIssueDescription"
        autocomplete="off"
        data-mention-users="{{ json_encode($redoMentionUsers->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
        placeholder="Describe the customer/QC issue. Type @ to mention someone, paste an image/screenshot, or use the editor tools."
    ></textarea>
    @error('redoIssueDescription')
        <small class="validation-error">{{ $message }}</small>
    @enderror
</label>
```

This reuses the project-wide `resources/js/components/rich-text.js`, so no second Redo-specific editor library is introduced.

## 3. `app/Livewire/Jobs/Concerns/ManagesOrderRedo.php`

Add imports:

```php
use App\Services\RichTextService;
use Illuminate\Validation\ValidationException;
```

The raw Livewire value can contain safe rich-text HTML and image URLs, so the transport validation uses the existing rich-text storage ceiling:

```php
'redoIssueDescription' => ['required', 'string', 'max:60000'],
```

Then normalize immediately in Step 1:

```php
$normalized = app(RichTextService::class)->normalize(
    $this->redoIssueDescription,
    5000,
    'redoIssueDescription',
);

if ($normalized === null) {
    throw ValidationException::withMessages([
        'redoIssueDescription' => 'Add an issue description or pasted image.',
    ]);
}

$this->redoIssueDescription = $normalized;
```

The 5,000-character limit applies to readable text. Pasted FlowTrack images are preserved without allowing arbitrary external HTML/images.

## 4. `app/Services/OrderRedoService.php`

The service normalizes the reason again before persistence as a server-side safety boundary:

```php
$storedIssueDescription = app(RichTextService::class)->normalize(
    (string) ($data['issue_description'] ?? ''),
    5000,
    'redoIssueDescription',
);

if ($storedIssueDescription === null) {
    throw ValidationException::withMessages([
        'redoIssueDescription' => 'Add an issue description or pasted image.',
    ]);
}

$data['issue_description'] = $storedIssueDescription;
$mentionIds = app(MentionService::class)
    ->userIdsFromText($storedIssueDescription);
```

`order_redos.issue_description` stores the rich content. The new Redo Order's generic `start_reason` receives a plain-text rendering to keep legacy Order fields compatible:

```php
'start_reason' => app(RichTextService::class)
    ->plainText($storedIssueDescription),
```

Mention IDs are included in Redo activity metadata and mentioned users receive the same FlowTrack mention notification used by normal Order comments/descriptions:

```php
app(NotificationService::class)->notifyMentionedUsers(
    $mentionIds,
    $actor->name.' mentioned you in '.$redoOrder->displayOrderNumber(),
    $storedIssueDescription,
    $redoOrder,
    null,
    $actor,
);
```

For **Discount instead of redo**, the notification links to the original Order because no replacement Order is created.

## 5. `resources/views/components/jobs/order-detail/redo-panel.blade.php`

The saved reason is rendered safely with existing mention/image rendering:

```blade
@if(filled($record->issue_description))
    <div class="ft-redo-info-row">
        <span>Issue reason</span>
        <div class="ft-rich-text-content">
            <x-ui.mention-text :text="$record->issue_description" />
        </div>
    </div>
@endif
```

This means formatted text, `@Name` mention styling and pasted images remain visible when reviewing Redo details.

## Existing infrastructure reused

No new editor package or upload route was added. The feature intentionally reuses:

- `resources/js/components/rich-text.js`
- `app/Services/RichTextService.php`
- `app/Services/MentionService.php`
- `app/Services/NotificationService.php`
- `RichTextImageController`
- `/rich-text-images` upload/show/download routes
- existing rich-text and mention CSS

## After copying files

Run:

```bash
php artisan view:clear
php artisan optimize:clear
```

If your deployed frontend build predates FlowTrack's shared rich-text editor, also run your normal Vite build. The current project already contains the shared editor code, so this Redo change itself does not add new JavaScript/CSS bundles.
