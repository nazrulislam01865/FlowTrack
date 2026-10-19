<?php

namespace App\Services;

use App\Models\Document;
use App\Models\FlowJob;
use App\Models\Task;
use App\Models\User;
use App\Support\SimplePdfDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class ArtworkTrackingPdfService
{
    public function generate(FlowJob $job, Task $reviewTask, User $actor, bool $force = false): Document
    {
        $uploadTask = Task::query()
            ->where('flow_job_id', $job->id)
            ->where('workflow_phase_id', $reviewTask->workflow_phase_id)
            ->with('setupTemplate')
            ->get()
            ->first(fn (Task $candidate): bool => app(OrderWorkflowActionService::class)->automationKey($candidate) === 'ART_PREPARE_UPLOAD');

        if (! $uploadTask) {
            // Older Task Packs sometimes moved upload tasks to another phase.
            // Only accept an unambiguous fallback for the same Order.
            $candidates = Task::query()
                ->where('flow_job_id', $job->id)
                ->with('setupTemplate')
                ->get()
                ->filter(fn (Task $candidate): bool => app(OrderWorkflowActionService::class)->automationKey($candidate) === 'ART_PREPARE_UPLOAD');
            $uploadTask = $candidates->count() === 1 ? $candidates->first() : null;
        }
        if (! $uploadTask) {
            throw new RuntimeException('The confirmed Artwork upload task could not be uniquely identified for this Order.');
        }

        $artwork = app(DocumentService::class)->currentArtworkDocuments($uploadTask);
        if ($artwork->isEmpty()) {
            throw new RuntimeException('Artwork cannot be confirmed until at least one current artwork file is available.');
        }

        $artworkVersion = max(1, (int) $artwork->max('version'));
        $existing = Document::query()
            ->where('flow_job_id', $job->id)
            ->where('category', Document::CATEGORY_ARTWORK_TRACKING_PDF)
            ->where('version', $artworkVersion)
            ->latest('id')
            ->first();

        if (! $force && $existing && app(SecureDocumentStorage::class)->locate((string) $existing->path)) {
            return $existing;
        }

        $job->ensureTrackingToken();
        $trackingUrl = $job->trackingUrl();
        $pdf = $this->render($artwork, $trackingUrl);

        $orderNumber = $job->job_number ?: $job->order_number ?: 'ORDER-'.$job->id;
        $safeOrder = preg_replace('/[^A-Za-z0-9._-]+/', '-', $orderNumber) ?: 'order-'.$job->id;
        $filename = trim($safeOrder, '-').'-confirmed-artwork-v'.$artworkVersion.'-tracking.pdf';
        $path = 'flowtrack/documents/'.$job->id.'/generated/'.$filename;
        $disk = Storage::disk((string) config('flowtrack.document_disk', 'flowtrack_private'));

        if (! $disk->put($path, $pdf)) {
            throw new RuntimeException('The confirmed artwork tracking PDF could not be stored.');
        }

        try {
            $document = Document::updateOrCreate(
                [
                    'flow_job_id' => $job->id,
                    'category' => Document::CATEGORY_ARTWORK_TRACKING_PDF,
                    'version' => $artworkVersion,
                ],
                [
                    'document_number' => 'DOC-TRACK-'.$job->id.'-V'.$artworkVersion,
                    'client_id' => $job->client_id,
                    'task_id' => $reviewTask->id,
                    'uploaded_by' => $actor->id,
                    'name' => $filename,
                    'path' => $path,
                    'mime_type' => 'application/pdf',
                    'size' => strlen($pdf),
                    'is_final' => true,
                    'note' => 'Generated automatically when the artwork was confirmed. Includes the confirmed artwork and the Order Tracking QR code.',
                ],
            );
        } catch (\Throwable $exception) {
            if (! $existing) {
                $disk->delete($path);
            }
            throw $exception;
        }

        return $document->refresh();
    }

    /** @param Collection<int,Document> $artwork */
    private function render(Collection $artwork, string $trackingUrl): string
    {
        $doc = new SimplePdfDocument();
        $navy = [0.055, 0.145, 0.285];
        $green = [0.00, 0.50, 0.42];
        $text = [0.08, 0.14, 0.24];
        $muted = [0.38, 0.44, 0.54];
        $border = [0.84, 0.88, 0.93];
        $companyHeader = $this->companyHeader();

        // Conversion happens only on confirmation / explicit repair, never in
        // a page render. Each request owns its temporary files and removes them.
        $directory = sys_get_temp_dir().'/flowtrack-artwork-'.bin2hex(random_bytes(12));
        if (! @mkdir($directory, 0700) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create a temporary artwork preview directory.');
        }
        try {
            $pageNumber = 0;
            foreach ($artwork as $position => $artworkDocument) {
                $previews = app(ArtworkPreviewRenderer::class)->pages($artworkDocument, $directory);
                foreach ($previews as $pageIndex => $preview) {
                    if ($pageNumber++ > 0) {
                        $doc->newPage();
                    }
                    $this->renderArtworkPage(
                        $doc, $artworkDocument, $preview, $trackingUrl,
                        $position + 1, $artwork->count(), $pageIndex + 1, count($previews),
                        $companyHeader, $border, $navy, $green, $text, $muted,
                    );
                }
            }
            return $doc->output();
        } finally {
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

    private function drawQr(SimplePdfDocument $doc, string $url, float $x, float $y, float $size): void
    {
        $matrix = app(QrCodeService::class)->booleanMatrix($url);
        $count = count($matrix);
        if ($count === 0) {
            throw new RuntimeException('The Order Tracking QR code could not be generated.');
        }

        $module = $size / $count;
        foreach ($matrix as $row => $columns) {
            foreach ($columns as $column => $dark) {
                if (! $dark) continue;
                $doc->fillRect(
                    $x + ($column * $module),
                    $y + $size - (($row + 1) * $module),
                    $module + 0.02,
                    $module + 0.02,
                    [0.02, 0.04, 0.06],
                );
            }
        }
    }

    private function renderArtworkPage(
        SimplePdfDocument $doc,
        Document $artwork,
        string $preview,
        string $trackingUrl,
        int $position,
        int $total,
        int $pageIndex,
        int $pageCount,
        array $companyHeader,
        array $border,
        array $navy,
        array $green,
        array $text,
        array $muted,
    ): void {
        $this->renderCompanyHeader($doc, $companyHeader, $navy, $text, $muted, $border);

        // Keep the document identification compact so the confirmed artwork
        // remains the focus of the page.
        $doc->text(42, 735, 'CONFIRMED ARTWORK', 17, true, $navy);
        if ($total > 1 || $pageCount > 1) {
            $doc->text(42, 716, 'Artwork '.$position.' of '.$total.' / Page '.$pageIndex.' of '.$pageCount, 7.5, false, $muted);
        }
        $doc->line(42, 706, 452, 706, 2.2, $green);

        // The tracking QR intentionally stays small and shares the same page
        // with the artwork. The URL itself remains live and reflects the
        // current order status whenever it is scanned.
        $qrSize = 68.0;
        $qrX = 476.0;
        $qrY = 666.0;
        $doc->fillRect($qrX - 5, $qrY - 5, $qrSize + 10, $qrSize + 10, [1, 1, 1]);
        $doc->rect($qrX - 5, $qrY - 5, $qrSize + 10, $qrSize + 10, 0.6, $border);
        $this->drawQr($doc, $trackingUrl, $qrX, $qrY, $qrSize);
        $doc->textCentered($qrX + ($qrSize / 2), 650, 'Scan for live order status', 6.8, true, $green);

        $frameX = 42.0;
        $frameY = 66.0;
        $frameWidth = 511.0;
        $frameHeight = 566.0;
        $padding = 12.0;
        $doc->rect($frameX, $frameY, $frameWidth, $frameHeight, 0.7, $border);

        $dimensions = @getimagesize($preview);
        if (! $dimensions || empty($dimensions[0]) || empty($dimensions[1])) {
            throw new RuntimeException('The confirmed artwork preview could not be read: '.$artwork->name);
        }
        $scale = min(
            ($frameWidth - 2 * $padding) / (float) $dimensions[0],
            ($frameHeight - 2 * $padding) / (float) $dimensions[1],
        );
        $width = max(1.0, (float) $dimensions[0] * $scale);
        $height = max(1.0, (float) $dimensions[1] * $scale);
        $x = $frameX + (($frameWidth - $width) / 2);
        $y = $frameY + (($frameHeight - $height) / 2);
        if (! $doc->image($preview, $x, $y, $width, $height)) {
            throw new RuntimeException('The confirmed artwork could not be embedded in the PDF: '.$artwork->name);
        }
    }

    /** @return array{name:string,legal_name:string,address:string,email:string,phone:string,website:string,registration:string,tax:string,logo_path:?string} */
    private function companyHeader(): array
    {
        $companyService = app(CompanyProfileService::class);
        $company = $companyService->current();
        $branding = app(BrandingService::class)->current();

        $name = trim((string) ($company['trading_name'] ?? ''))
            ?: trim((string) ($company['legal_name'] ?? ''))
            ?: trim((string) ($branding['name'] ?? ''))
            ?: 'FlowTrack';
        $legalName = trim((string) ($company['legal_name'] ?? '')) ?: $name;

        return [
            'name' => $name,
            'legal_name' => $legalName,
            'address' => implode(', ', $companyService->addressLines($company)),
            'email' => trim((string) ($company['billing_email'] ?? '')),
            'phone' => trim((string) ($company['phone'] ?? '')),
            'website' => trim((string) ($company['website'] ?? '')),
            'registration' => trim((string) ($company['registration_number'] ?? '')),
            'tax' => trim((string) ($company['tax_number'] ?? '')),
            'logo_path' => $this->brandingLogoPath($branding),
        ];
    }

    /** @param array{name:string,legal_name:string,address:string,email:string,phone:string,website:string,registration:string,tax:string,logo_path:?string} $company */
    private function renderCompanyHeader(
        SimplePdfDocument $doc,
        array $company,
        array $navy,
        array $text,
        array $muted,
        array $border,
    ): void {
        $logoDrawn = $this->drawLogo($doc, $company['logo_path'], 42, 790, 118, 28);

        // Do not repeat the company name when the configured logo is present.
        // If no logo is available, the trading/legal name is the compact
        // fallback so the generated document still identifies the company.
        if (! $logoDrawn) {
            $doc->wrappedText(42, 808, $this->plain($company['name']), 210, 10.5, 12, true, $navy, 1);
        }

        $rightLines = [];
        if ($company['address'] !== '') {
            $rightLines[] = $this->plain($company['address']);
        }

        $contact = array_values(array_filter([
            $company['phone'] !== '' ? $this->plain($company['phone']) : null,
            $company['email'] !== '' ? $this->plain($company['email']) : null,
        ]));
        if ($contact !== []) {
            $rightLines[] = implode('  |  ', $contact);
        }

        if ($company['website'] !== '') {
            $rightLines[] = $this->plain($company['website']);
        }

        $rightY = 807.0;
        foreach (array_slice($rightLines, 0, 3) as $line) {
            $doc->textRight(553, $rightY, $this->compact($line, 58), 7.2, false, $text);
            $rightY -= 11;
        }

        $doc->line(42, 772, 553, 772, 0.7, $border);
    }

    private function brandingLogoPath(array $branding): ?string
    {
        $path = trim((string) ($branding['logo_path'] ?? ''));
        if ($path === '') return null;

        try {
            $disk = Storage::disk('public');
            if (! $disk->exists($path)) return null;
            return $disk->path($path);
        } catch (\Throwable) {
            return null;
        }
    }

    private function drawLogo(SimplePdfDocument $doc, ?string $path, float $x, float $y, float $maxWidth, float $maxHeight): bool
    {
        if (! $path || ! is_file($path)) return false;
        $info = @getimagesize($path);
        if (! $info || empty($info[0]) || empty($info[1])) return false;

        $scale = min($maxWidth / (float) $info[0], $maxHeight / (float) $info[1], 1.0);
        $width = max(1.0, (float) $info[0] * $scale);
        $height = max(1.0, (float) $info[1] * $scale);

        return $doc->image($path, $x, $y, $width, $height);
    }

    private function compact(string $value, int $limit): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) <= $limit) return $value;
        return rtrim(mb_substr($value, 0, max(1, $limit - 1))).'...';
    }

    private function plain(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? $value);
    }
}
