<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Private, quarantine-first document storage with legacy dual-read support.
 */
class SecureDocumentStorage
{
    public function __construct(private readonly UploadSecurityService $security) {}

    /** @return array{path:string,mime:string,size:int,scan_engine:string,extension:string,extension_normalized:bool} */
    public function store(UploadedFile $file, string $directory, ?int $maxFileBytes = null): array
    {
        $directory = trim($directory, '/');
        abort_if($directory === '' || str_contains($directory, '..'), 500, 'Invalid secure storage directory.');

        $extension = strtolower(trim((string) $file->getClientOriginalExtension()));
        $storedStem = Str::uuid()->toString();
        $storedName = $storedStem.($extension !== '' ? '.'.$extension : '');
        $quarantinePath = 'pending/'.Str::uuid()->toString().'/'.$storedName;
        $quarantineDisk = Storage::disk((string) config('flowtrack.quarantine_disk', 'flowtrack_quarantine'));

        $stored = $quarantineDisk->putFileAs(dirname($quarantinePath), $file, basename($quarantinePath));
        abort_if(! $stored, 500, 'The upload could not be quarantined.');

        try {
            [$absolutePath, $temporaryCopy] = $this->inspectionPath($quarantineDisk, $quarantinePath);
            try {
                $scan = $this->security->inspect($absolutePath, $file->getClientOriginalName(), $file->getMimeType(), $maxFileBytes);
            } finally {
                if ($temporaryCopy && is_file($absolutePath)) {
                    @unlink($absolutePath);
                }
            }

            $verifiedExtension = strtolower(trim((string) ($scan['extension'] ?? $extension)));
            $targetName = $storedStem.($verifiedExtension !== '' ? '.'.$verifiedExtension : '');
            $targetPath = $directory.'/'.$targetName;
            $targetDisk = Storage::disk((string) config('flowtrack.document_disk', 'flowtrack_private'));
            $stream = $quarantineDisk->readStream($quarantinePath);
            abort_if($stream === false, 500, 'The quarantined upload could not be read.');
            try {
                $written = $targetDisk->writeStream($targetPath, $stream);
            } finally {
                if (is_resource($stream)) fclose($stream);
            }
            abort_unless($written, 500, 'The verified upload could not be promoted to private storage.');

            $quarantineDisk->delete($quarantinePath);
            Log::info('flowtrack.document.promoted', [
                'path' => $targetPath,
                'original_name' => basename($file->getClientOriginalName()),
                'scan_engine' => (string) $scan['engine'],
                'size' => (int) $scan['size'],
            ]);

            return [
                'path' => $targetPath,
                'mime' => (string) $scan['mime'],
                'size' => (int) $scan['size'],
                'scan_engine' => (string) $scan['engine'],
                'extension' => $verifiedExtension,
                'extension_normalized' => (bool) ($scan['extension_normalized'] ?? false),
            ];
        } catch (\Throwable $exception) {
            // A rejected or failed scan intentionally stays on the quarantine
            // disk for operator review/retention cleanup and is never linked to
            // a Document/InquiryDocument/Invoice/Payment record.
            Log::warning('flowtrack.document.quarantined', [
                'quarantine_path' => $quarantinePath,
                'original_name' => basename($file->getClientOriginalName()),
                'reason' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }

    /**
     * Return a local path suitable for signature/malware inspection.
     *
     * Local/shared-mount disks expose path() directly. Object disks do not,
     * so the quarantined object is streamed into a short-lived OS temp file,
     * inspected there, and removed before the request finishes. No business
     * record ever points at the temporary copy.
     *
     * @return array{0:string,1:bool}
     */
    private function inspectionPath($disk, string $path): array
    {
        try {
            $absolute = $disk->path($path);
            if (is_string($absolute) && $absolute !== '' && is_file($absolute)) {
                return [$absolute, false];
            }
        } catch (\Throwable) {
            // Remote/object adapters intentionally fall through to streaming.
        }

        $stream = $disk->readStream($path);
        abort_if($stream === false, 500, 'The quarantined upload could not be inspected.');
        $temporary = tempnam(sys_get_temp_dir(), 'flowtrack-upload-');
        abort_if($temporary === false, 500, 'A temporary inspection file could not be created.');

        $target = fopen($temporary, 'wb');
        if ($target === false) {
            if (is_resource($stream)) fclose($stream);
            @unlink($temporary);
            abort(500, 'A temporary inspection file could not be opened.');
        }

        try {
            stream_copy_to_stream($stream, $target);
        } finally {
            if (is_resource($stream)) fclose($stream);
            fclose($target);
        }

        return [$temporary, true];
    }

    /** @return array{disk:string,path:string}|null */
    public function locate(string $path): ?array
    {
        $path = ltrim(trim($path), '/');
        if ($path === '' || str_contains($path, '../')) return null;

        foreach ($this->readDisks() as $diskName) {
            try {
                if (Storage::disk($diskName)->exists($path)) return ['disk' => $diskName, 'path' => $path];
            } catch (\Throwable) {
                // Continue to the next compatibility disk.
            }
        }

        return null;
    }

    public function delete(string $path): void
    {
        $path = ltrim(trim($path), '/');
        if ($path === '' || str_contains($path, '../')) return;

        foreach ($this->readDisks() as $diskName) {
            try {
                if (Storage::disk($diskName)->exists($path) && Storage::disk($diskName)->delete($path)) {
                    Log::info('flowtrack.document.deleted', ['disk' => $diskName, 'path' => $path]);
                }
            } catch (\Throwable) {
                // Deletion is best-effort across legacy compatibility disks.
            }
        }
    }

    /** @return list<string> */
    public function readDisks(): array
    {
        $primary = (string) config('flowtrack.document_disk', 'flowtrack_private');
        $legacy = (array) config('flowtrack.legacy_document_disks', ['public', 'local']);
        return array_values(array_unique(array_filter(array_merge([$primary], array_map('strval', $legacy)))));
    }
}
