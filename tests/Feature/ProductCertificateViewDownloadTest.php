<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ProductCertificateViewDownloadTest extends TestCase
{
    public function test_certificate_actions_use_distinct_inline_and_download_links(): void
    {
        $document = [
            'kind' => 'certificate',
            'label' => 'Production-Test-Report.pdf',
            'url' => '/master-data/products/7/documents/certificate/abc.pdf',
            'download_url' => '/master-data/products/7/documents/certificate/abc.pdf?download=1',
        ];

        $html = Blade::render('<x-catalog.product-document-card title="Certificate & Test Report" :document="$document" />', compact('document'));

        $this->assertStringContainsString('>View</a>', $html);
        $this->assertStringContainsString('>Download</a>', $html);
        $this->assertStringContainsString('href="'.$document['url'].'"', $html);
        $this->assertStringContainsString('href="'.$document['download_url'].'"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
    }

    public function test_certificate_file_badge_reflects_actual_format(): void
    {
        foreach (['pdf' => 'PDF', 'docx' => 'DOC', 'xlsx' => 'XLS', 'png' => 'IMG', 'zip' => 'ZIP', 'csv' => 'CSV', 'txt' => 'TXT'] as $extension => $badge) {
            $document = [
                'kind' => 'certificate',
                'label' => 'report.'.$extension,
                'url' => '/example.'.$extension,
                'download_url' => '/example.'.$extension.'?download=1',
            ];

            $html = Blade::render('<x-catalog.product-document-card title="Certificate & Test Report" :document="$document" />', compact('document'));
            $this->assertStringContainsString('>'.$badge.'</span>', $html, 'Incorrect badge for '.$extension);
        }
    }

    public function test_missing_certificate_has_no_broken_file_actions(): void
    {
        $html = Blade::render('<x-catalog.product-document-card title="Certificate & Test Report" />');
        $this->assertStringContainsString('No file', $html);
        $this->assertStringNotContainsString('>View</a>', $html);
        $this->assertStringNotContainsString('>Download</a>', $html);
    }

    public function test_certificate_file_route_preserves_authorization_and_attachment_response(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/ProductDocumentController.php'));
        $this->assertStringContainsString("canModule('catalog_products', 'view')", $controller);
        $this->assertStringContainsString('StoredFileResponse::inline(', $controller);
        $this->assertStringContainsString('StoredFileResponse::download(', $controller);
        $this->assertStringContainsString("'certificate_test_report_path'", $controller);
    }
}
