<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Services\ArtworkPreviewRenderer;
use App\Support\SimplePdfDocument;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ArtworkPreviewRendererTest extends TestCase
{
    public function test_pdf_artwork_renders_every_page_in_the_original_order(): void
    {
        $this->requireProgram('pdfinfo');
        $this->requireProgram('pdftoppm');

        $pdf = new SimplePdfDocument();
        $pdf->text(60, 650, 'PAGE ONE', 22, true);
        $pdf->newPage();
        $pdf->text(60, 650, 'PAGE TWO', 22, true);

        $diskName = (string) config('flowtrack.document_disk', 'flowtrack_private');
        Storage::fake($diskName);
        $path = 'flowtrack/documents/test/source.pdf';
        Storage::disk($diskName)->put($path, $pdf->output());

        $artwork = new Document(['name' => 'Artwork.pdf', 'path' => $path, 'mime_type' => 'application/pdf']);
        $artwork->id = 9801;
        $temp = sys_get_temp_dir().'/flowtrack-preview-test-'.bin2hex(random_bytes(8));
        mkdir($temp, 0700);
        try {
            $pages = app(ArtworkPreviewRenderer::class)->pages($artwork, $temp);
            $this->assertCount(2, $pages);
            $this->assertNotSame($pages[0], $pages[1]);
            foreach ($pages as $page) {
                $this->assertFileExists($page);
                $this->assertNotFalse(getimagesize($page));
            }
        } finally {
            $this->removeTempDirectory($temp);
        }
    }

    public function test_word_compatible_artwork_is_converted_to_visible_pages(): void
    {
        $this->requireProgram('soffice');
        $this->requireProgram('pdfinfo');
        $this->requireProgram('pdftoppm');

        $diskName = (string) config('flowtrack.document_disk', 'flowtrack_private');
        Storage::fake($diskName);
        $path = 'flowtrack/documents/test/source.doc';
        Storage::disk($diskName)->put($path, '{\\rtf1\\ansi\\deff0\\fs28 Artwork supplied as a Word document\\par}');
        $artwork = new Document(['name' => 'Artwork.doc', 'path' => $path, 'mime_type' => 'application/msword']);
        $artwork->id = 9802;
        $temp = sys_get_temp_dir().'/flowtrack-preview-test-'.bin2hex(random_bytes(8));
        mkdir($temp, 0700);
        try {
            $pages = app(ArtworkPreviewRenderer::class)->pages($artwork, $temp);
            $this->assertNotEmpty($pages);
            $this->assertNotFalse(getimagesize($pages[0]));
        } finally {
            $this->removeTempDirectory($temp);
        }
    }

    public function test_unknown_artwork_format_never_silently_becomes_filename_only_reference(): void
    {
        $diskName = (string) config('flowtrack.document_disk', 'flowtrack_private');
        Storage::fake($diskName);
        $path = 'flowtrack/documents/test/artwork.zip';
        Storage::disk($diskName)->put($path, 'fake archive for unsupported-format test');
        $artwork = new Document(['name' => 'Artwork.zip', 'path' => $path]);
        $artwork->id = 9803;
        $temp = sys_get_temp_dir().'/flowtrack-preview-test-'.bin2hex(random_bytes(8));
        mkdir($temp, 0700);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('cannot be previewed');
            app(ArtworkPreviewRenderer::class)->pages($artwork, $temp);
        } finally {
            $this->removeTempDirectory($temp);
        }
    }

    private function requireProgram(string $program): void
    {
        try {
            $process = new Process([$program, $program === 'soffice' ? '--version' : '-v']);
            $process->run();
            if ($process->isSuccessful()) {
                return;
            }
        } catch (\Throwable) {
        }
        $this->markTestSkipped($program.' is not installed for artwork preview integration tests.');
    }

    private function removeTempDirectory(string $directory): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($directory);
    }
}
