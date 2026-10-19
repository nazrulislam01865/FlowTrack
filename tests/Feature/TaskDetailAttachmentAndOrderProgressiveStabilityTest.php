<?php

namespace Tests\Feature;

use Tests\TestCase;

class TaskDetailAttachmentAndOrderProgressiveStabilityTest extends TestCase
{
    public function test_task_detail_attachment_upload_is_authorization_driven_not_view_mode_driven(): void
    {
        $view = file_get_contents(resource_path('views/components/jobs/task-detail.blade.php'));
        $attachments = file_get_contents(resource_path('views/components/jobs/task-detail/attachments.blade.php'));
        $resources = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderTaskResources.php'));

        $this->assertStringContainsString('$canUploadDocument = $canEditTask &&', $view);
        $this->assertStringNotContainsString('$canUploadDocument = $editMode &&', $view);
        $this->assertStringContainsString('data-auto-upload-method="uploadSelectedTaskDocuments"', $attachments);
        $this->assertStringNotContainsString('Choose from Documents', $attachments);
        $this->assertStringContainsString('canEditTask(auth()->user(), $task)', $resources);
        $this->assertStringContainsString("\$storeData['require_task_pack_requirement'] = true;", $resources);
    }

    public function test_order_progressive_loading_is_scoped_to_the_current_order_and_stale_requests_are_ignored(): void
    {
        $overview = file_get_contents(resource_path('views/components/jobs/detail-overview.blade.php'));
        $loader = file_get_contents(resource_path('views/components/ui/progressive-section-loader.blade.php'));
        $concern = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesDetailProgressiveLoading.php'));
        $pageData = file_get_contents(app_path('Livewire/Jobs/Concerns/BuildsOrderPageData.php'));

        $this->assertGreaterThanOrEqual(4, substr_count($overview, 'context-type="order"'));
        $this->assertGreaterThanOrEqual(4, substr_count($overview, ':context-id="$job->id"'));
        $this->assertStringContainsString("'-'.\$contextType.'-'.\$contextId", $loader);
        $this->assertStringContainsString("if (\$contextType === 'order')", $concern);
        $this->assertStringContainsString('(int) $this->selectedJobId !== (int) $contextId', $concern);
        $this->assertStringContainsString("\$this->orderDetailSectionsReady['workflow'] = true;", $pageData);
        $this->assertStringContainsString("\$this->orderDetailSectionsReady['products'] = true;", $pageData);
    }
}
