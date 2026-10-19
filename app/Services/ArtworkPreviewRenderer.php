<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Rasterize existing, privately stored artwork for the confirmed reference PDF.
 * Runs only while generating a confirmed PDF, never while showing Order Details.
 * Each converter receives a private temporary copy (never a public URL).
 */
final class ArtworkPreviewRenderer
{
    private const MAX_PAGES = 40;

    /** @return list<string> Local image paths in their original page order. */
    public function pages(Document $artwork, string $temporaryDirectory): array
    {
        $location = app(SecureDocumentStorage::class)->locate((string) $artwork->path);
        if ($location === null) {
            throw new RuntimeException('The source artwork file is missing: '.$artwork->name);
        }

        $directory = $temporaryDirectory.'/document-'.(int) $artwork->id;
        if (! @mkdir($directory, 0700) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create a private artwork preview directory.');
        }

        // The storage service already verified the uploaded file. Keep its
        // original format when making the isolated converter input.
        $extension = strtolower((string) pathinfo((string) $artwork->path, PATHINFO_EXTENSION));
        if (! preg_match('/\A[a-z0-9]{1,8}\z/', $extension)) {
            throw new RuntimeException('The artwork filename has no supported file extension.');
        }
        $source = $directory.'/source.'.$extension;
        $stream = Storage::disk($location['disk'])->readStream($location['path']);
        if ($stream === false) {
            throw new RuntimeException('Unable to read the source artwork file: '.$artwork->name);
        }
        $target = fopen($source, 'wb');
        if (! is_resource($target)) {
            fclose($stream);
            throw new RuntimeException('Unable to prepare the source artwork preview.');
        }
        try {
            stream_copy_to_stream($stream, $target);
        } finally {
            fclose($stream);
            fclose($target);
        }

        if (in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
            return [$source];
        }
        if ($extension === 'webp' && function_exists('imagecreatefromwebp')) {
            return [$source];
        }
        if ($extension === 'gif' && function_exists('imagecreatefromgif')) {
            $image = @imagecreatefromgif($source);
            if ($image !== false) {
                $converted = $directory.'/preview.png';
                try {
                    if (imagepng($image, $converted)) {
                        return [$converted];
                    }
                } finally {
                    imagedestroy($image);
                }
            }
        }
        if (in_array($extension, ['webp', 'gif'], true)) {
            $converted = $directory.'/preview.png';
            $this->run([
                (string) config('flowtrack.artwork_pdf_tools.image_converter', 'magick'),
                $source.'[0]', $converted,
            ], 45, 'Raster artwork conversion requires ImageMagick (magick).');
            if (! is_file($converted) || filesize($converted) === 0) {
                throw new RuntimeException('Raster artwork preview was not created: '.$artwork->name);
            }
            return [$converted];
        }

        if ($extension === 'pdf') {
            return $this->pdfPages($source, $directory);
        }

        if (in_array($extension, ['doc', 'docx', 'odt', 'rtf', 'xls', 'xlsx', 'ods', 'ppt', 'pptx', 'odp', 'txt', 'csv', 'ai', 'eps', 'esp', 'cdr'], true)) {
            $office = (string) config('flowtrack.artwork_pdf_tools.office_converter', 'soffice');
            $profile = $directory.'/office-profile';
            $this->run([
                $office,
                '-env:UserInstallation=file://'.$profile,
                '--headless', '--convert-to', 'pdf', '--outdir', $directory, $source,
            ], 90, 'Office/vector artwork conversion requires LibreOffice (soffice).');

            $converted = $directory.'/source.pdf';
            if (! is_file($converted) || filesize($converted) === 0) {
                throw new RuntimeException('LibreOffice could not convert '.strtoupper($extension).' artwork to PDF. Export the file as a PDF and upload it again: '.$artwork->name);
            }
            return $this->pdfPages($converted, $directory);
        }

        // ZIP/archive files cannot be visually confirmed as one artwork page.
        throw new RuntimeException('Artwork format '.strtoupper($extension).' cannot be previewed. Please upload a PDF, image, or Office document.');
    }

    /** @return list<string> */
    private function pdfPages(string $pdf, string $directory): array
    {
        $info = $this->run([
            (string) config('flowtrack.artwork_pdf_tools.pdfinfo', 'pdfinfo'), $pdf,
        ], 20, 'PDF artwork conversion requires Poppler (pdfinfo and pdftoppm).');

        if (! preg_match('/^Pages:\s*(\d+)\s*$/mi', $info, $matches)) {
            throw new RuntimeException('The artwork PDF is unreadable or protected; its pages could not be counted.');
        }
        $pages = (int) $matches[1];
        if ($pages < 1 || $pages > self::MAX_PAGES) {
            throw new RuntimeException('Artwork PDF must contain between 1 and '.self::MAX_PAGES.' pages.');
        }

        $prefix = $directory.'/artwork-page';
        $this->run([
            (string) config('flowtrack.artwork_pdf_tools.pdf_renderer', 'pdftoppm'),
            '-f', '1', '-l', (string) $pages, '-scale-to', '1400',
            '-jpeg', '-jpegopt', 'quality=86', $pdf, $prefix,
        ], 120, 'PDF artwork rendering requires Poppler (pdftoppm).');

        $images = glob($prefix.'-*.jpg') ?: [];
        natsort($images);
        $images = array_values($images);
        if (count($images) !== $pages) {
            throw new RuntimeException('The artwork preview generated only '.count($images).' of '.$pages.' PDF pages.');
        }
        return $images;
    }

    /** @param list<string> $command */
    private function run(array $command, int $timeout, string $requirements): string
    {
        try {
            $process = new Process($command);
            $process->setTimeout($timeout);
            $process->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException(
                    rtrim($requirements).' Artwork conversion failed: '.trim(mb_substr($process->getErrorOutput() ?: $process->getOutput(), 0, 500))
                );
            }
            return $process->getOutput();
        } catch (\Symfony\Component\Process\Exception\ProcessFailedException|\Symfony\Component\Process\Exception\ProcessTimedOutException|\Symfony\Component\Process\Exception\RuntimeException $exception) {
            throw new RuntimeException($requirements.' '.$exception->getMessage(), previous: $exception);
        }
    }
}
