<?php

namespace Tests\Feature;

use Tests\TestCase;

class CreateAttachmentDropzoneReuseTest extends TestCase
{
    public function test_inquiry_and_order_create_forms_share_the_same_attachment_dropzone_component(): void
    {
        $component = file_get_contents(resource_path('views/components/ui/create-attachment-dropzone.blade.php'));
        $inquiry = file_get_contents(resource_path('views/livewire/inquiries/sections/create.blade.php'));
        $order = file_get_contents(resource_path('views/components/jobs/create.blade.php'));
        $orderDocuments = file_get_contents(resource_path('views/components/jobs/create/documents.blade.php'));

        $this->assertStringContainsString('ft-create-attachment-dropzone', $component);
        $this->assertStringContainsString('x-on:livewire-upload-progress', $component);
        $this->assertStringContainsString('data-file-dropzone', $component);
        $this->assertStringContainsString("'variant' => 'default'", $component);
        $this->assertStringContainsString('<x-ui.create-attachment-dropzone', $inquiry);
        $this->assertStringContainsString('<x-jobs.create.documents', $order);
        $this->assertSame(2, substr_count($orderDocuments, '<x-ui.create-attachment-dropzone'));
        $this->assertStringContainsString('model="purchaseOrderUpload"', $orderDocuments);
        $this->assertStringContainsString('model="jobAttachments"', $orderDocuments);
        $this->assertSame(2, substr_count($orderDocuments, 'variant="order-document"'));
    }
}
