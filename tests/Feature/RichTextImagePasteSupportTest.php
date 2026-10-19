<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Support\OrderPhase5Source;
use App\Services\RichTextService;
use Illuminate\Support\Facades\Storage;

class RichTextImagePasteSupportTest extends TestCase
{
    public function test_operational_descriptions_and_comments_use_the_shared_rich_text_editor(): void
    {
        $jobCreate = file_get_contents(resource_path('views/components/jobs/create.blade.php'));
        $jobOverview = file_get_contents(resource_path('views/components/jobs/order-detail/overview-card.blade.php'));
        $taskDetail = OrderPhase5Source::taskDetailView();
        $jobActivity = file_get_contents(resource_path('views/components/jobs/detail-activity.blade.php'));
        $inquiries = $this->inquiryViewSource();
        $inquiryActivity = file_get_contents(resource_path('views/livewire/inquiries/_activity.blade.php'));

        $this->assertStringContainsString('data-rich-text wire:model="description"', $jobCreate);
        $this->assertStringContainsString('data-rich-text', $jobOverview);
        $this->assertStringContainsString('autocomplete="off"', $jobOverview);
        $this->assertGreaterThanOrEqual(2, substr_count($taskDetail, 'data-rich-text'));
        $this->assertStringContainsString('data-rich-text data-rich-text-compact wire:model="jobComment"', $jobActivity);
        $this->assertGreaterThanOrEqual(3, substr_count($inquiries, 'data-rich-text'));
        $this->assertStringContainsString('data-rich-text data-rich-text-compact wire:model="inquiryComment"', $inquiryActivity);
    }

    public function test_rich_text_runtime_uploads_clipboard_images_instead_of_storing_base64_html(): void
    {
        $runtime = file_get_contents(resource_path('js/components/rich-text.js'));
        $routes = file_get_contents(base_path('routes/web.php'));

        $this->assertStringContainsString("meta[name=\"flowtrack-rich-text-upload-url\"]", $runtime);
        $this->assertStringContainsString('event.clipboardData?.items', $runtime);
        $this->assertStringContainsString('const clipboardFiles = directFiles.length ? directFiles : itemFiles;', $runtime);
        $this->assertStringContainsString('const seenFiles = new Set();', $runtime);
        $this->assertStringNotContainsString('const files = [...directFiles, ...itemFiles]', $runtime);
        $this->assertStringContainsString("body.append('image', file", $runtime);
        $this->assertStringContainsString("Route::post('/rich-text-images'", $routes);
        $this->assertStringContainsString("Route::get('/rich-text-images/{filename}'", $routes);
    }

    public function test_rich_text_is_sanitized_and_mentions_still_work(): void
    {
        $richText = file_get_contents(app_path('Services/RichTextService.php'));
        $mentions = file_get_contents(app_path('Services/MentionService.php'));

        $this->assertStringContainsString("strip_tags(\$html, '<p><div><br><strong><b><em><i><u><ul><ol><li><img>')", $richText);
        $this->assertStringContainsString('rich-text-images/([A-Za-z0-9-]+', $richText);
        $this->assertStringContainsString("route('rich-text-images.show', ['filename' => \$imageMatch[1]], false)", $richText);
        $this->assertStringContainsString('RichTextService::class)->plainText', $mentions);
        $this->assertStringContainsString('$richText = app(RichTextService::class);', $mentions);
        $this->assertStringContainsString('$richText->safeHtml($text)', $mentions);
    }

    public function test_rich_mention_notifications_keep_the_original_comment_for_existing_deep_links(): void
    {
        $notifications = file_get_contents(app_path('Services/NotificationService.php'));
        $dashboard = file_get_contents(app_path('Services/LegacyDashboardService.php'));

        $this->assertGreaterThanOrEqual(2, substr_count($notifications, "'message' => \$message"));
        $this->assertStringContainsString('dashboardMentionQuery', $dashboard);
        $this->assertStringContainsString("flow_notifications.flow_task_id", $dashboard);
        $this->assertStringContainsString("flow_notifications.inquiry_id", $dashboard);
        $this->assertStringNotContainsString("whereColumn('flow_task_comments.body', 'flow_notifications.message')", $dashboard);
        $this->assertStringNotContainsString("whereColumn('activities.description', 'flow_notifications.message')", $dashboard);
    }
    public function test_inline_description_saves_wait_for_images_and_use_one_rich_text_source_of_truth(): void
    {
        $runtime = file_get_contents(resource_path('js/components/rich-text.js'));
        $inlineEditing = file_get_contents(resource_path('js/components/inline-edit.js'));
        $jobOverview = file_get_contents(resource_path('views/components/jobs/order-detail/overview-card.blade.php'));
        $taskDetail = OrderPhase5Source::taskDetailView();
        $inquiries = $this->inquiryViewSource();
        $jobsIndex = OrderPhase5Source::livewire();
        $inquiriesIndex = $this->inquiryLivewireSource();

        $this->assertStringContainsString('source.__flowtrackRichTextValueAsync = async () =>', $runtime);
        $this->assertStringContainsString('await Promise.allSettled(Array.from(pendingUploads))', $runtime);
        $this->assertStringContainsString('source.__flowtrackRichTextSetValue = (value) =>', $runtime);
        $this->assertStringContainsString('async saveRichText(source, emptyDisplay, requestFactory)', $inlineEditing);
        $this->assertMatchesRegularExpression('/saveRichText\(\s*\$refs\.orderOverviewDescription/s', $jobOverview);
        $this->assertStringContainsString('saveRichText($refs.description', $taskDetail);
        $this->assertStringContainsString('saveRichText($refs.inquiryDescription', $inquiries);
        $this->assertStringContainsString('hasRichTextOverride', $inlineEditing);
        $this->assertStringContainsString('richTextOverrideHtml', $inlineEditing);
        $this->assertStringContainsString('displayHtml', $jobsIndex);
        $this->assertStringContainsString('displayHtml', $inquiriesIndex);
        $this->assertStringNotContainsString("saveRichText(\$refs.descriptionEditor, 'No order description recorded.', (clean) => \$wire.updateJobTextField({{ \$job->id }}, 'description', clean)).then", $jobOverview);
        $this->assertStringNotContainsString("saveRichText(\$refs.description, 'No description has been provided for this task.', (clean) => \$wire.updateSelectedTaskField('description', clean)).then", $taskDetail);
        $this->assertStringNotContainsString("saveRichText(\$refs.inquiryDescription, 'No description has been provided for this Inquiry.', (clean) => \$wire.updateInquiryField('requirement_notes', clean)).then", $inquiries);
        $this->assertStringNotContainsString('x-ref="orderOverviewDescription" x-model="draftValue"', $jobOverview);
        $this->assertStringNotContainsString('x-ref="description" x-model="draftValue"', $taskDetail);
        $this->assertStringNotContainsString('x-ref="inquiryDescription" x-model="draftValue"', $inquiries);
    }

    public function test_rich_text_image_sanitizer_supports_app_subdirectory_urls(): void
    {
        $richText = file_get_contents(app_path('Services/RichTextService.php'));

        $this->assertStringContainsString("(?:^|/)rich-text-images/", $richText);
        $this->assertStringContainsString("route('rich-text-images.show', ['filename' => \$imageMatch[1]], false)", $richText);
        $this->assertStringContainsString('richTextImageUrl($imageMatch[1])', $richText);
        $this->assertStringNotContainsString("#^/rich-text-images/", $richText);
    }

    public function test_saved_rich_text_images_have_zoom_preview_and_download_support(): void
    {
        $runtime = file_get_contents(resource_path('js/components/rich-text.js'));
        $routes = file_get_contents(base_path('routes/web.php'));
        $controller = file_get_contents(app_path('Http/Controllers/RichTextImageController.php'));
        $css = $this->applicationCss();

        $this->assertStringContainsString(".ft-rich-text-content img", $runtime);
        $this->assertStringContainsString('bootRichTextImageViewer', $runtime);
        $this->assertStringContainsString('data-rich-image-download', $runtime);
        $this->assertStringContainsString('data-rich-image-zoom-in', $runtime);
        $this->assertStringContainsString('data-rich-image-zoom-out', $runtime);
        $this->assertStringContainsString("Route::get('/rich-text-images/{filename}/download'", $routes);
        $this->assertStringContainsString('public function download(string $filename)', $controller);
        $this->assertStringContainsString("StoredFileResponse::download(\$path, \$filename", $controller);
        $this->assertStringContainsString('.ft-rich-image-viewer', $css);
        $this->assertMatchesRegularExpression('/cursor\s*:\s*zoom-in/', $css);
    }

    public function test_saved_rich_text_images_can_be_presented_as_compact_file_rows(): void
    {
        Storage::fake('flowtrack_private');
        config()->set('flowtrack.document_disk', 'flowtrack_private');
        config()->set('flowtrack.legacy_document_disks', []);

        $service = app(RichTextService::class);
        $filename = '123e4567-e89b-12d3-a456-426614174000.png';
        Storage::disk('flowtrack_private')->put('rich-text-images/'.$filename, 'image-bytes');
        $stored = RichTextService::MARKER
            .'<p>Move the logo to the left.</p><img src="'.route('rich-text-images.show', ['filename' => $filename], false).'">';

        $images = $service->imageAttachments($stored);

        $this->assertCount(1, $images);
        $this->assertSame($filename, $images[0]['name']);
        $this->assertSame('PNG', $images[0]['extension']);
        $this->assertStringContainsString('/rich-text-images/'.$filename, $images[0]['url']);
        $this->assertStringContainsString('/rich-text-images/'.$filename.'/download', $images[0]['download_url']);
        $this->assertStringContainsString('Move the logo to the left.', (string) $service->withoutImages($stored));
        $this->assertStringNotContainsString('<img', (string) $service->withoutImages($stored));
    }

    public function test_missing_rich_text_images_are_suppressed_at_render_time_without_mutating_stored_source(): void
    {
        Storage::fake('flowtrack_private');
        config()->set('flowtrack.document_disk', 'flowtrack_private');
        config()->set('flowtrack.legacy_document_disks', []);

        $service = app(RichTextService::class);
        $filename = '123e4567-e89b-12d3-a456-426614174099.png';
        $stored = RichTextService::MARKER
            .'<p>Keep this instruction.</p><img src="'.route('rich-text-images.show', ['filename' => $filename], false).'">';

        $normalized = $service->normalize($stored);
        $rendered = $service->safeHtml($stored);

        $this->assertStringContainsString($filename, (string) $normalized);
        $this->assertStringNotContainsString($filename, (string) $rendered);
        $this->assertStringContainsString('Keep this instruction.', (string) $rendered);
        $this->assertSame([], $service->imageAttachments($stored));
    }

    public function test_artwork_revision_activity_uses_compact_image_references(): void
    {
        $wideActivity = file_get_contents(resource_path('views/components/jobs/order-detail/activity.blade.php'));
        $friendlyActivity = file_get_contents(resource_path('views/components/jobs/detail-activity.blade.php'));
        $revisionContent = file_get_contents(resource_path('views/components/jobs/order-detail/revision-activity-content.blade.php'));

        $this->assertStringContainsString("job.artwork_revision_requested", $wideActivity);
        $this->assertStringContainsString('x-jobs.order-detail.revision-activity-content', $wideActivity);
        $this->assertStringContainsString('x-jobs.order-detail.revision-activity-content', $friendlyActivity);
        $this->assertStringContainsString('revision_comment', $revisionContent);
        $this->assertStringContainsString('withoutImages', $revisionContent);
        $this->assertStringContainsString('imageAttachments', $revisionContent);
        $this->assertStringContainsString('ft-revision-activity-thumbnail', $revisionContent);
        $this->assertStringContainsString('download_url', $revisionContent);
    }

    public function test_rich_text_survives_livewire_navigation_and_viewer_body_replacement(): void
    {
        $runtime = file_get_contents(resource_path('js/components/rich-text.js'));
        $app = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("'morph.added'", $runtime);
        $this->assertStringContainsString("'morphed'", $runtime);
        $this->assertStringContainsString('state.observedBody === document.body', $runtime);
        $this->assertStringContainsString('state.imageViewerController.ensureOverlay()', $runtime);
        $navigation = file_get_contents(resource_path('js/core/navigation.js'));
        $this->assertStringContainsString("document.addEventListener('livewire:navigating'", $navigation);
        $this->assertStringContainsString('event.detail?.onSwap?.(() => {', $app);
        $this->assertStringContainsString('scheduleRichTextRefresh();', $app);
    }


    public function test_enter_posts_compact_comments_and_shift_enter_keeps_a_new_line(): void
    {
        $runtime = file_get_contents(resource_path('js/components/rich-text.js'));

        $this->assertStringContainsString('const shouldSubmitComment = compactComment', $runtime);
        $this->assertStringContainsString("event.key === 'Enter'", $runtime);
        $this->assertStringContainsString('!event.shiftKey', $runtime);
        $this->assertStringContainsString('!event.isComposing', $runtime);
        $this->assertStringContainsString("source.closest('.ft-comment-composer')", $runtime);
        $this->assertStringContainsString('Enter to comment · Shift+Enter for a new line', $runtime);
    }
}
