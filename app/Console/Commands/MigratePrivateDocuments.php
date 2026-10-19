<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\InquiryDocument;
use App\Models\Invoice;
use App\Models\MasterRecord;
use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigratePrivateDocuments extends Command
{
    protected $signature = 'flowtrack:migrate-private-documents {--delete-source : Delete legacy source only after verified private copy}';
    protected $description = 'Copy referenced business documents from legacy disks into FlowTrack private storage.';

    public function handle(): int
    {
        $targetName = (string) config('flowtrack.document_disk', 'flowtrack_private');
        $legacyNames = array_values(array_filter((array) config('flowtrack.legacy_document_disks', ['public', 'local']), fn ($disk) => $disk !== $targetName));
        $target = Storage::disk($targetName);
        $deleteSource = (bool) $this->option('delete-source');
        $paths = [];

        Document::query()->select(['id', 'path'])->whereNotNull('path')->chunkById(500, function ($rows) use (&$paths): void {
            foreach ($rows as $row) $paths[(string) $row->path] = true;
        });
        InquiryDocument::query()->select(['id', 'path'])->whereNotNull('path')->chunkById(500, function ($rows) use (&$paths): void {
            foreach ($rows as $row) $paths[(string) $row->path] = true;
        });
        Invoice::query()->select(['id', 'supporting_document_path'])->whereNotNull('supporting_document_path')->chunkById(500, function ($rows) use (&$paths): void {
            foreach ($rows as $row) $paths[(string) $row->supporting_document_path] = true;
        });
        Payment::query()->select(['id', 'receipt_path'])->whereNotNull('receipt_path')->chunkById(500, function ($rows) use (&$paths): void {
            foreach ($rows as $row) $paths[(string) $row->receipt_path] = true;
        });
        MasterRecord::query()->where('type', 'product')->select(['id', 'metadata'])->chunkById(250, function ($rows) use (&$paths): void {
            foreach ($rows as $row) {
                foreach (['certificate_test_report_path', 'template_doc_path'] as $key) {
                    $path = trim((string) data_get($row->metadata, $key));
                    if ($path !== '') $paths[$path] = true;
                }
            }
        });

        // Rich-text images are route-authorized but not represented by a model.
        foreach ($legacyNames as $legacyName) {
            try {
                foreach (Storage::disk($legacyName)->allFiles('rich-text-images') as $path) $paths[$path] = true;
            } catch (\Throwable) {
            }
        }

        $copied = 0;
        $alreadyPrivate = 0;
        $missing = 0;
        $deleted = 0;

        foreach (array_keys($paths) as $path) {
            $path = ltrim(trim($path), '/');
            if ($path === '' || str_contains($path, '../')) continue;

            if ($target->exists($path)) {
                $alreadyPrivate++;
                if ($deleteSource) $deleted += $this->deleteLegacyCopies($path, $legacyNames);
                continue;
            }

            $sourceName = null;
            foreach ($legacyNames as $legacyName) {
                try {
                    if (Storage::disk($legacyName)->exists($path)) {
                        $sourceName = $legacyName;
                        break;
                    }
                } catch (\Throwable) {
                }
            }
            if ($sourceName === null) {
                $missing++;
                $this->warn('Missing referenced file: '.$path);
                continue;
            }

            $source = Storage::disk($sourceName);
            $stream = $source->readStream($path);
            if ($stream === false) {
                $missing++;
                $this->warn('Could not read legacy file: '.$path);
                continue;
            }
            try {
                $ok = $target->writeStream($path, $stream);
            } finally {
                if (is_resource($stream)) fclose($stream);
            }
            if (! $ok || ! $target->exists($path)) {
                $this->error('Private copy verification failed: '.$path);
                return self::FAILURE;
            }

            $copied++;
            if ($deleteSource) $deleted += $this->deleteLegacyCopies($path, $legacyNames);
        }

        $this->info("Private document migration complete. copied={$copied}, already_private={$alreadyPrivate}, missing={$missing}, legacy_deleted={$deleted}");
        if (! $deleteSource) {
            $this->comment('Legacy sources were retained. Re-run with --delete-source after verifying application access.');
        }
        return $missing > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @param list<string> $legacyNames */
    private function deleteLegacyCopies(string $path, array $legacyNames): int
    {
        $deleted = 0;
        foreach ($legacyNames as $legacyName) {
            try {
                $disk = Storage::disk($legacyName);
                if ($disk->exists($path) && $disk->delete($path)) $deleted++;
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
        return $deleted;
    }
}
