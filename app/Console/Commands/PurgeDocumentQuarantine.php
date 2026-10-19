<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PurgeDocumentQuarantine extends Command
{
    protected $signature = 'flowtrack:purge-document-quarantine {--hours=}';
    protected $description = 'Delete expired quarantined document uploads that were never promoted.';

    public function handle(): int
    {
        $hours = max(1, (int) ($this->option('hours') ?: config('flowtrack.upload_security.quarantine_retention_hours', 72)));
        $cutoff = now()->subHours($hours)->timestamp;
        $disk = Storage::disk((string) config('flowtrack.quarantine_disk', 'flowtrack_quarantine'));
        $deleted = 0;

        foreach ($disk->allFiles('pending') as $path) {
            try {
                if ((int) $disk->lastModified($path) <= $cutoff && $disk->delete($path)) $deleted++;
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        $this->info("Purged {$deleted} expired quarantined upload(s).");
        return self::SUCCESS;
    }
}
