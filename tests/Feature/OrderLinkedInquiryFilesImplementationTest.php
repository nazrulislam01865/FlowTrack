<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderLinkedInquiryFilesImplementationTest extends TestCase
{
    public function test_order_inquiry_tab_loads_and_renders_files_for_every_linked_inquiry_without_copying_them(): void
    {
        $service = file_get_contents(app_path('Services/LegacyJobService.php'));
        $builder = file_get_contents(app_path('Livewire/Jobs/Concerns/BuildsOrderPageData.php'));
        $detail = file_get_contents(resource_path('views/components/jobs/detail.blade.php'));
        $index = file_get_contents(resource_path('views/livewire/jobs/index.blade.php'));
        $inquiry = file_get_contents(resource_path('views/components/jobs/detail-inquiry.blade.php'));

        $this->assertStringContainsString("can(\$user, 'documents', 'view')", $service);
        $this->assertStringContainsString('linkedInquiries.documents:id,inquiry_id,inquiry_task_id,uploaded_by,name,mime_type,size,created_at', $service);
        $this->assertStringContainsString('linkedInquiries.documents.uploader:id,name,profile_image_path', $service);
        $this->assertStringContainsString('linkedInquiries.documents.task:id,title', $service);

        $this->assertStringContainsString('canViewLinkedInquiryDocuments', $builder);
        $this->assertStringContainsString("can(\$user, 'documents', 'export')", $builder);
        $this->assertStringContainsString("'canViewLinkedInquiryDocuments'=>false", $detail);
        $this->assertStringContainsString("'canExportLinkedInquiryDocuments'=>false", $detail);
        $this->assertStringContainsString(':can-view-linked-inquiry-documents="$canViewLinkedInquiryDocuments"', $detail);
        $this->assertStringContainsString(':can-export-linked-inquiry-documents="$canExportLinkedInquiryDocuments"', $detail);
        $this->assertStringContainsString(':can-view-linked-inquiry-documents="$canViewLinkedInquiryDocuments ?? false"', $index);
        $this->assertStringContainsString(':can-export-linked-inquiry-documents="$canExportLinkedInquiryDocuments ?? false"', $index);

        $this->assertStringContainsString('@foreach($linkedInquiries as $linked)', $inquiry);
        $this->assertStringContainsString('Inquiry files', $inquiry);
        $this->assertStringContainsString("route('inquiries.documents.open', \$document)", $inquiry);
        $this->assertStringContainsString("route('inquiries.documents.download', \$document)", $inquiry);
        $this->assertStringContainsString('nothing is copied into the Order', $inquiry);
        $this->assertStringNotContainsString('copy(', $inquiry);
    }
}
