<?php

namespace Tests\Feature;

use Tests\TestCase;

class Phase10DocumentAccessAuthorizationTest extends TestCase
{
    public function test_every_business_document_response_remains_behind_authorization(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));
        $finance = file_get_contents(app_path('Http/Controllers/FinanceAttachmentController.php'));
        $product = file_get_contents(app_path('Http/Controllers/ProductDocumentController.php'));

        $this->assertStringContainsString('applyInquiryDocumentArchiveScope', $routes);
        $this->assertStringContainsString('applyDocumentScope', $routes);
        $this->assertStringContainsString("canModule('documents', 'view')", $routes);
        $this->assertStringContainsString("canModule('documents', 'export')", $routes);
        $this->assertStringContainsString('authorizeFinanceRecord', $finance);
        $this->assertStringContainsString('can($user, \'finance\', \'view\')', $finance);
        $this->assertStringContainsString("canModule('catalog_products', 'view')", $product);
        $this->assertStringContainsString('StoredFileResponse::', $product);
    }

    public function test_public_storage_is_not_the_authoritative_business_document_disk(): void
    {
        $flowtrack = file_get_contents(config_path('flowtrack.php'));
        $filesystems = file_get_contents(config_path('filesystems.php'));

        $this->assertStringContainsString("FLOWTRACK_DOCUMENT_DISK', 'flowtrack_private", $flowtrack);
        $this->assertStringContainsString("'flowtrack_private' => [", $filesystems);
        $this->assertStringNotContainsString("public_path('flowtrack-private')", $filesystems);
    }
}
