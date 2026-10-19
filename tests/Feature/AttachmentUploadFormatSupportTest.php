<?php

namespace Tests\Feature;

use App\Support\AttachmentUpload;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AttachmentUploadFormatSupportTest extends TestCase
{
    public function test_shared_business_attachment_list_contains_every_supported_format(): void
    {
        $expected = [
            'pdf', 'doc', 'docx', 'xls', 'xlsx',
            'jpg', 'jpeg', 'png', 'webp', 'gif', 'ico',
            'zip', 'txt', 'csv', 'ai', 'eps', 'esp', 'cdr',
        ];

        $this->assertSame($expected, AttachmentUpload::extensions());

        $accept = AttachmentUpload::accept();
        foreach ($expected as $extension) {
            $this->assertStringContainsString('.'.$extension, $accept);
        }
    }

    public function test_design_formats_with_unreliable_mime_reporting_are_accepted_by_shared_rules(): void
    {
        foreach (['ai', 'eps', 'esp', 'cdr'] as $extension) {
            $file = UploadedFile::fake()->create('artwork.'.$extension, 32, 'application/octet-stream');
            $validator = validator(
                ['attachment' => $file],
                ['attachment' => AttachmentUpload::requiredRules()],
            );

            $this->assertTrue($validator->passes(), $extension.': '.$validator->errors()->first('attachment'));
        }
    }

    public function test_legacy_attachment_profiles_now_share_the_same_global_business_formats(): void
    {
        $expected = AttachmentUpload::BUSINESS_DOCUMENTS;

        $this->assertSame($expected, AttachmentUpload::DOCUMENTS);
        $this->assertSame($expected, AttachmentUpload::DOCUMENTS_WITH_AI);
        $this->assertSame($expected, AttachmentUpload::ORDER_REQUIRED);
        $this->assertSame($expected, AttachmentUpload::FINANCE);
        $this->assertSame($expected, AttachmentUpload::PRODUCT_SUPPORTING);
    }

    public function test_business_attachment_uploaders_render_the_shared_accept_source(): void
    {
        $views = [
            resource_path('views/components/jobs/create/documents.blade.php'),
            resource_path('views/components/jobs/documents/required-document-uploader.blade.php'),
            resource_path('views/components/jobs/order-detail/attachments.blade.php'),
            resource_path('views/components/jobs/order-detail/document-modal.blade.php'),
            resource_path('views/components/jobs/order-detail/workflow-action-modal.blade.php'),
            resource_path('views/components/jobs/task-detail/attachments.blade.php'),
            resource_path('views/components/jobs/finance/create-invoice-modal.blade.php'),
            resource_path('views/components/jobs/finance/payment-modal.blade.php'),
            resource_path('views/livewire/inquiries/_attachments.blade.php'),
            resource_path('views/livewire/inquiries/sections/detail.blade.php'),
            resource_path('views/livewire/documents/index.blade.php'),
            resource_path('views/components/rfq/public/documents.blade.php'),
            resource_path('views/components/rfq/public/pricing.blade.php'),
            resource_path('views/components/catalog/product-form.blade.php'),
        ];

        foreach ($views as $view) {
            $contents = file_get_contents($view);
            $this->assertStringContainsString('AttachmentUpload', $contents, $view);
        }
    }
}
