<?php

namespace Tests\Feature;

use App\Services\SecureDocumentStorage;
use App\Support\StoredFileResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class Phase10DocumentSecurityTest extends TestCase
{
    public function test_secure_storage_quarantines_scans_and_promotes_to_private_disk(): void
    {
        Storage::fake('flowtrack_private');
        Storage::fake('flowtrack_quarantine');
        Storage::fake('public');
        config()->set('flowtrack.document_disk', 'flowtrack_private');
        config()->set('flowtrack.quarantine_disk', 'flowtrack_quarantine');
        config()->set('flowtrack.legacy_document_disks', ['public']);
        config()->set('flowtrack.upload_security.scanner', 'basic');

        $file = UploadedFile::fake()->createWithContent('spec.pdf', "%PDF-1.7\nFlowTrack secure upload\n");
        $stored = app(SecureDocumentStorage::class)->store($file, 'flowtrack/documents/1');

        Storage::disk('flowtrack_private')->assertExists($stored['path']);
        Storage::disk('public')->assertMissing($stored['path']);
        $this->assertSame([], Storage::disk('flowtrack_quarantine')->allFiles('pending'));
        $this->assertStringStartsWith('flowtrack/documents/1/', $stored['path']);
        $this->assertNotSame('spec.pdf', basename($stored['path']));
    }


    public function test_pdf_with_utf8_bom_and_leading_whitespace_is_accepted(): void
    {
        Storage::fake('flowtrack_private');
        Storage::fake('flowtrack_quarantine');
        config()->set('flowtrack.document_disk', 'flowtrack_private');
        config()->set('flowtrack.quarantine_disk', 'flowtrack_quarantine');
        config()->set('flowtrack.upload_security.scanner', 'basic');

        // Common Illustrator/design-export edge case: Fileinfo correctly
        // identifies this as PDF even though %PDF- is not byte zero.
        $file = UploadedFile::fake()->createWithContent(
            'artwork.pdf',
            "\xEF\xBB\xBF\r\n%PDF-1.7\nFlowTrack artwork\n",
        );

        $stored = app(SecureDocumentStorage::class)->store($file, 'flowtrack/documents/1');

        Storage::disk('flowtrack_private')->assertExists($stored['path']);
        $this->assertSame([], Storage::disk('flowtrack_quarantine')->allFiles('pending'));
    }

    public function test_safe_raster_image_with_stale_extension_is_normalized_instead_of_rejected(): void
    {
        Storage::fake('flowtrack_private');
        Storage::fake('flowtrack_quarantine');
        config()->set('flowtrack.document_disk', 'flowtrack_private');
        config()->set('flowtrack.quarantine_disk', 'flowtrack_quarantine');
        config()->set('flowtrack.upload_security.scanner', 'basic');

        // Common client-side conversion edge case: the file is genuinely a
        // JPEG, but the old PNG filename was retained by the browser/exporter.
        $file = UploadedFile::fake()->createWithContent(
            'FO-333998.PNG',
            "\xFF\xD8\xFF\xE0".str_repeat("\x00", 128),
        );

        $stored = app(SecureDocumentStorage::class)->store($file, 'flowtrack/documents/1');

        $this->assertSame('jpg', $stored['extension']);
        $this->assertTrue($stored['extension_normalized']);
        $this->assertSame('image/jpeg', $stored['mime']);
        $this->assertStringEndsWith('.jpg', $stored['path']);
        Storage::disk('flowtrack_private')->assertExists($stored['path']);
        $this->assertSame('image/jpeg', StoredFileResponse::mimeType('FO-333998.PNG', $stored['mime']));
    }

    public function test_fake_png_with_no_valid_raster_signature_is_still_rejected(): void
    {
        Storage::fake('flowtrack_private');
        Storage::fake('flowtrack_quarantine');
        config()->set('flowtrack.document_disk', 'flowtrack_private');
        config()->set('flowtrack.quarantine_disk', 'flowtrack_quarantine');
        config()->set('flowtrack.upload_security.scanner', 'basic');

        $file = UploadedFile::fake()->createWithContent('fake.PNG', 'not an image');

        try {
            app(SecureDocumentStorage::class)->store($file, 'flowtrack/documents/1');
            $this->fail('A non-image file with a PNG extension must remain rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertStringContainsString('fake.PNG', $exception->getMessage());
            $this->assertSame([], Storage::disk('flowtrack_private')->allFiles());
        }
    }

    public function test_mislabeled_pdf_is_still_rejected_by_signature_validation(): void
    {
        Storage::fake('flowtrack_private');
        Storage::fake('flowtrack_quarantine');
        config()->set('flowtrack.document_disk', 'flowtrack_private');
        config()->set('flowtrack.quarantine_disk', 'flowtrack_quarantine');
        config()->set('flowtrack.upload_security.scanner', 'basic');

        $file = UploadedFile::fake()->createWithContent('not-really.pdf', 'plain text pretending to be a PDF');

        try {
            app(SecureDocumentStorage::class)->store($file, 'flowtrack/documents/1');
            $this->fail('A mislabeled PDF must not be promoted.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertStringContainsString('not-really.pdf', $exception->getMessage());
            $this->assertSame([], Storage::disk('flowtrack_private')->allFiles());
        }
    }

    public function test_script_upload_is_rejected_before_promotion(): void
    {
        Storage::fake('flowtrack_private');
        Storage::fake('flowtrack_quarantine');
        config()->set('flowtrack.document_disk', 'flowtrack_private');
        config()->set('flowtrack.quarantine_disk', 'flowtrack_quarantine');

        $file = UploadedFile::fake()->createWithContent('payload.php', '<?php echo "bad";');

        try {
            app(SecureDocumentStorage::class)->store($file, 'flowtrack/documents/1');
            $this->fail('A script upload must not be promoted.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertSame([], Storage::disk('flowtrack_private')->allFiles());
            $this->assertNotSame([], Storage::disk('flowtrack_quarantine')->allFiles('pending'));
        }
    }



    public function test_cdr_is_accepted_as_a_private_business_document_and_forced_to_download(): void
    {
        Storage::fake('flowtrack_private');
        Storage::fake('flowtrack_quarantine');
        config()->set('flowtrack.document_disk', 'flowtrack_private');
        config()->set('flowtrack.quarantine_disk', 'flowtrack_quarantine');
        config()->set('flowtrack.legacy_document_disks', []);
        config()->set('flowtrack.upload_security.scanner', 'basic');

        // CDR MIME detection varies by CorelDRAW version/exporter. The secure
        // layer therefore treats it like AI/EPS: private storage + download
        // only, while executable/script signatures are still rejected.
        $file = UploadedFile::fake()->createWithContent('customer-artwork.cdr', "RIFF\x10\x00\x00\x00CDR6FlowTrack");
        $stored = app(SecureDocumentStorage::class)->store($file, 'flowtrack/documents/1');

        Storage::disk('flowtrack_private')->assertExists($stored['path']);
        $response = StoredFileResponse::inline($stored['path'], 'customer-artwork.cdr', 'application/octet-stream');
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
    }

    public function test_eps_and_postscript_like_files_are_never_rendered_inline(): void
    {
        Storage::fake('flowtrack_private');
        config()->set('flowtrack.document_disk', 'flowtrack_private');
        config()->set('flowtrack.legacy_document_disks', []);
        Storage::disk('flowtrack_private')->put('flowtrack/documents/1/art.eps', "%!PS-Adobe-3.0 EPSF-3.0\n");

        $response = StoredFileResponse::inline('flowtrack/documents/1/art.eps', 'customer-artwork.eps', 'application/postscript');
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
    }
}
