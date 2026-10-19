<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderCreateUploadProgressAndOwnerPickerStabilityTest extends TestCase
{
    public function test_create_order_purchase_order_and_other_documents_show_real_upload_progress(): void
    {
        $documents = file_get_contents(resource_path('views/components/jobs/create/documents.blade.php'));
        $dropzone = file_get_contents(resource_path('views/components/ui/create-attachment-dropzone.blade.php'));
        $css = file_get_contents(resource_path('css/modules/orders/create-documents.css'));

        $this->assertStringContainsString('x-on:livewire-upload-progress="updateUpload($event)"', $dropzone);
        $this->assertStringContainsString('Uploading purchase order...', $documents);
        $this->assertStringContainsString('progress-aria-label="Purchase order upload progress"', $documents);
        $this->assertStringContainsString('progress-aria-label="Order document upload progress"', $documents);
        $this->assertStringContainsString('ft-create-attachment-progress-track', $dropzone);
        $this->assertStringContainsString('.ft-create-job-page .ft-create-attachment-progress', $css);
    }

    public function test_repeated_owner_picker_use_keeps_compact_recent_page_and_remeasures_unconstrained_options(): void
    {
        $runtime = file_get_contents(resource_path('js/components/list-filters.js'));
        $picker = file_get_contents(resource_path('views/components/ui/inline-remote-user.blade.php'));
        $header = file_get_contents(resource_path('views/components/jobs/order-detail/header.blade.php'));
        $planning = file_get_contents(resource_path('views/components/jobs/order-detail/planning.blade.php'));
        $css = file_get_contents(resource_path('css/modules/orders/detail/detail-02.css'));

        $this->assertStringContainsString('const REMOTE_RECENT_PAGE_SIZE = 5;', $runtime);
        $this->assertStringContainsString('restoreCompactRecentPage()', $runtime);
        $this->assertStringContainsString("url.searchParams.set('per_page', String(q ? REMOTE_SEARCH_PAGE_SIZE : this.recentPageSize));", $runtime);
        $this->assertStringNotContainsString('recentPerPage', $runtime);
        $this->assertStringContainsString("this.menuStyle = '';", $runtime);
        $this->assertStringContainsString("list.style.setProperty('flex', '0 0 auto', 'important')", $runtime);
        $this->assertStringContainsString("list.style.setProperty('max-height', 'none', 'important')", $runtime);
        $this->assertStringContainsString("'instanceKey' => ''", $picker);
        $this->assertStringContainsString('instance-key="order-header-owner"', $header);
        $this->assertStringContainsString('instance-key="order-planning-owner"', $planning);
        $this->assertStringContainsString('.header-owner-picker .ft-remote-filter-list:has(.ft-remote-filter-option)', $css);
    }
}
