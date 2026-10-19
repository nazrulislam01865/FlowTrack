<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use App\Support\AttachmentUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Chunk staging for large Order Artwork files.
 *
 * Livewire 4.3 uploads a selected file as one temporary HTTP request. That is
 * fragile for 100-400MB artwork because PHP, Nginx, a load balancer, or a slow
 * connection can terminate the request before Livewire receives the file.
 *
 * This service stores small chunks on FlowTrack's quarantine disk, validates
 * ownership/task access on every request, and materializes the finished file
 * only when the normal DocumentService is ready to scan and persist it.
 */
class ArtworkUploadStagingService
{
    private const ROOT = 'staged-artwork';

    /** @return array{token:string,name:string,size:int,type:string,revision_document_id:?int,complete:bool} */
    public function start(Task $task, User $user, string $originalName, int $size, ?int $revisionDocumentId = null): array
    {
        $task = $this->authorizedArtworkTask($task, $user, $revisionDocumentId);
        $this->purgeExpiredForUser($user);

        $name = $this->safeOriginalName($originalName);
        abort_if($size <= 0, 422, 'Empty artwork files are not allowed.');
        abort_if($size > AttachmentUpload::ARTWORK_MAX_BYTES, 422, 'Each artwork file must be 400 MB or smaller.');

        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        abort_unless(in_array($extension, AttachmentUpload::extensions(), true), 422, AttachmentUpload::validationMessage());

        $token = (string) Str::uuid();
        $manifest = [
            'token' => $token,
            'user_id' => (int) $user->id,
            'task_id' => (int) $task->id,
            'flow_job_id' => (int) $task->flow_job_id,
            'revision_document_id' => $revisionDocumentId ? (int) $revisionDocumentId : null,
            'original_name' => $name,
            'size' => $size,
            'chunk_bytes' => $this->chunkBytes(),
            'chunk_count' => (int) ceil($size / $this->chunkBytes()),
            'received_bytes' => 0,
            // next_index is retained as a received-chunk count for compatibility
            // with any short-lived staged manifests created before this release.
            'next_index' => 0,
            'chunk_sizes' => [],
            'complete' => false,
            'created_at' => now()->timestamp,
            'updated_at' => now()->timestamp,
        ];

        $this->writeManifest($manifest);

        return $this->descriptor($manifest);
    }

    /** @return array{token:string,name:string,size:int,type:string,revision_document_id:?int,complete:bool,received_bytes:int,progress:int} */
    public function appendChunk(string $token, User $user, UploadedFile $chunk, int $index): array
    {
        abort_unless($chunk->isValid(), 422, 'This artwork chunk could not be uploaded. Please retry.');
        $chunkSize = (int) $chunk->getSize();
        abort_if($chunkSize <= 0, 422, 'An empty artwork chunk was received.');
        abort_if($index < 0, 422, 'Invalid artwork upload chunk index.');

        // Parallel browser workers may finish in any order. The lock protects
        // only this upload's manifest/chunk commit so duplicate retries remain
        // idempotent and received bytes can never be counted twice.
        return Cache::lock('flowtrack:artwork-upload:'.$token, 30)->block(20, function () use ($token, $user, $chunk, $index, $chunkSize): array {
            $manifest = $this->manifest($token, $user);
            $task = Task::query()->findOrFail((int) $manifest['task_id']);
            $this->authorizedArtworkTask($task, $user, $this->nullableInt($manifest['revision_document_id'] ?? null));

            abort_if((bool) ($manifest['complete'] ?? false), 409, 'This artwork upload is already complete.');

            $total = (int) ($manifest['size'] ?? 0);
            $configuredChunkBytes = max(1, (int) ($manifest['chunk_bytes'] ?? $this->chunkBytes()));
            $chunkCount = max(1, (int) ($manifest['chunk_count'] ?? ceil($total / $configuredChunkBytes)));
            abort_if($index >= $chunkCount, 422, 'Invalid artwork upload chunk index.');

            $begin = $index * $configuredChunkBytes;
            $expectedChunkBytes = min($configuredChunkBytes, max(0, $total - $begin));
            abort_if($expectedChunkBytes <= 0 || $chunkSize !== $expectedChunkBytes, 422, 'The artwork upload chunk size is invalid. Please retry the file.');

            $chunkSizes = is_array($manifest['chunk_sizes'] ?? null) ? $manifest['chunk_sizes'] : [];
            $key = (string) $index;
            $disk = $this->disk();
            $directory = $this->basePath($manifest).'/chunks';
            $filename = sprintf('%06d.part', $index);
            $chunkPath = $directory.'/'.$filename;

            // A response can be lost after the server successfully committed a
            // chunk. Retrying the same index returns current progress rather than
            // rewriting/counting it again.
            if (array_key_exists($key, $chunkSizes) && $disk->exists($chunkPath)) {
                abort_if((int) $chunkSizes[$key] !== $chunkSize, 409, 'This artwork chunk conflicts with an already uploaded chunk. Please choose the file again.');
                return $this->progressDescriptor($manifest);
            }

            $stored = $disk->putFileAs($directory, $chunk, $filename);
            abort_if(! $stored, 500, 'The artwork chunk could not be staged. Please retry.');

            $chunkSizes[$key] = $chunkSize;
            $manifest['chunk_count'] = $chunkCount;
            $manifest['chunk_sizes'] = $chunkSizes;
            $manifest['received_bytes'] = array_sum(array_map('intval', $chunkSizes));
            $manifest['next_index'] = count($chunkSizes);
            $manifest['updated_at'] = now()->timestamp;
            $this->writeManifest($manifest);

            return $this->progressDescriptor($manifest);
        });
    }

    /** @return array{token:string,name:string,size:int,type:string,revision_document_id:?int,complete:bool} */
    public function complete(string $token, User $user): array
    {
        return Cache::lock('flowtrack:artwork-upload:'.$token, 30)->block(20, function () use ($token, $user): array {
            $manifest = $this->manifest($token, $user);
            $task = Task::query()->findOrFail((int) $manifest['task_id']);
            $this->authorizedArtworkTask($task, $user, $this->nullableInt($manifest['revision_document_id'] ?? null));

            $expected = (int) ($manifest['size'] ?? 0);
            $configuredChunkBytes = max(1, (int) ($manifest['chunk_bytes'] ?? $this->chunkBytes()));
            $chunkCount = max(1, (int) ($manifest['chunk_count'] ?? ceil($expected / $configuredChunkBytes)));
            $chunkSizes = is_array($manifest['chunk_sizes'] ?? null) ? $manifest['chunk_sizes'] : [];
            $received = array_sum(array_map('intval', $chunkSizes));

            abort_unless($expected > 0 && $received === $expected, 422, 'The artwork upload is incomplete. Please retry the remaining chunks.');
            abort_unless(count($chunkSizes) === $chunkCount, 422, 'The artwork upload is missing one or more chunks. Please retry the file.');

            $disk = $this->disk();
            for ($index = 0; $index < $chunkCount; $index++) {
                $key = (string) $index;
                $chunkPath = $this->basePath($manifest).'/chunks/'.sprintf('%06d.part', $index);
                $expectedChunkBytes = min($configuredChunkBytes, max(0, $expected - ($index * $configuredChunkBytes)));
                abort_unless(array_key_exists($key, $chunkSizes), 422, 'The artwork upload is missing one or more chunks. Please retry the file.');
                abort_unless((int) $chunkSizes[$key] === $expectedChunkBytes && $disk->exists($chunkPath), 422, 'A staged artwork chunk is incomplete. Please upload the file again.');
            }

            $manifest['chunk_count'] = $chunkCount;
            $manifest['received_bytes'] = $received;
            $manifest['next_index'] = count($chunkSizes);
            $manifest['complete'] = true;
            $manifest['updated_at'] = now()->timestamp;
            $this->writeManifest($manifest);

            return $this->descriptor($manifest);
        });
    }

    /** @return array{token:string,name:string,size:int,type:string,revision_document_id:?int,complete:bool} */
    public function describe(string $token, User $user, Task $task): array
    {
        $manifest = $this->manifest($token, $user);
        abort_unless((int) $manifest['task_id'] === (int) $task->id, 422, 'This staged artwork belongs to a different task.');
        $this->authorizedArtworkTask($task, $user, $this->nullableInt($manifest['revision_document_id'] ?? null));
        abort_unless((bool) ($manifest['complete'] ?? false), 422, 'The artwork file is still uploading.');

        return $this->descriptor($manifest);
    }

    /**
     * Turn completed chunk sets into ordinary UploadedFile instances so the
     * existing quarantine scan, naming/versioning, and DocumentService logic
     * remain the single persistence path.
     *
     * Keys from $tokens are preserved (revision uploads are keyed by source
     * document id).
     *
     * @param array<int|string,string> $tokens
     * @return array{files:array<int|string,UploadedFile>,temporary_paths:list<string>}
     */
    public function materialize(array $tokens, User $user, Task $task): array
    {
        $this->extendArtworkExecutionWindow();

        $files = [];
        $temporaryPaths = [];

        try {
            foreach ($tokens as $key => $token) {
                $manifest = $this->manifest((string) $token, $user);
                abort_unless((int) $manifest['task_id'] === (int) $task->id, 422, 'One staged artwork file belongs to a different task.');
                $this->authorizedArtworkTask($task, $user, $this->nullableInt($manifest['revision_document_id'] ?? null));
                abort_unless((bool) ($manifest['complete'] ?? false), 422, 'One artwork file is still uploading.');

                $path = $this->materializeManifest($manifest);
                $temporaryPaths[] = $path;
                $files[$key] = new UploadedFile(
                    $path,
                    (string) $manifest['original_name'],
                    null,
                    UPLOAD_ERR_OK,
                    true,
                );
            }
        } catch (\Throwable $exception) {
            $this->releaseMaterialized($temporaryPaths);
            throw $exception;
        }

        return ['files' => $files, 'temporary_paths' => $temporaryPaths];
    }

    /** @param list<string> $paths */
    public function releaseMaterialized(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** @param array<int|string,string> $tokens */
    public function consume(array $tokens, User $user): void
    {
        foreach (array_unique(array_filter(array_map('strval', array_values($tokens)))) as $token) {
            $this->discard($token, $user);
        }
    }

    public function discard(string $token, User $user): void
    {
        try {
            $manifest = $this->manifest($token, $user, false);
            if (! $manifest || (int) ($manifest['user_id'] ?? 0) !== (int) $user->id) return;
            $this->disk()->deleteDirectory($this->basePath($manifest));
        } catch (\Throwable) {
            // Cleanup is best effort. Expired/partially removed uploads are
            // swept on the next start request for this user.
        }
    }

    private function authorizedArtworkTask(Task $task, User $user, ?int $revisionDocumentId): Task
    {
        abort_unless($user->canModule('documents', 'create'), 403);

        $visible = app(TaskService::class)->visibleQuery($user)
            ->with(['job', 'setupTemplate'])
            ->whereKey($task->id)
            ->firstOrFail();
        abort_unless(app(AccessControlService::class)->canEditTask($user, $visible), 403);

        $automationKey = app(OrderWorkflowActionService::class)->automationKey($visible);
        abort_unless(in_array($automationKey, ['ART_PREPARE_UPLOAD', 'ART_SAMPLE_APPROVAL'], true), 422, 'Chunked upload is available only for the Artwork phase.');

        if ($automationKey === 'ART_SAMPLE_APPROVAL') {
            abort_if($revisionDocumentId !== null, 422, 'Sample Approval does not accept selective artwork revision targets.');
            return $visible;
        }

        $revision = app(DocumentService::class)->pendingArtworkRevision($visible);
        $revisionActive = (bool) ($revision['active'] ?? false);
        if ($revisionActive) {
            abort_unless($revisionDocumentId !== null, 422, 'Choose the artwork file this replacement belongs to.');
            $allowed = collect($revision['document_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->contains($revisionDocumentId);
            abort_unless($allowed, 422, 'This artwork is not part of the current revision request.');
        } else {
            abort_if($revisionDocumentId !== null, 422, 'There is no active selective artwork revision for this task.');
        }

        return $visible;
    }

    /** @return array<string,mixed>|null */
    private function manifest(string $token, User $user, bool $required = true): ?array
    {
        abort_unless(Str::isUuid($token), 422, 'Invalid artwork upload token.');
        $path = self::ROOT.'/'.(int) $user->id.'/'.$token.'/manifest.json';
        $disk = $this->disk();

        if (! $disk->exists($path)) {
            abort_if($required, 422, 'This artwork upload expired or is no longer available. Please choose the file again.');
            return null;
        }

        $decoded = json_decode((string) $disk->get($path), true);
        abort_unless(is_array($decoded), 422, 'The staged artwork upload is invalid. Please choose the file again.');
        abort_unless((int) ($decoded['user_id'] ?? 0) === (int) $user->id, 403);
        abort_if($this->isExpired($decoded), 422, 'This artwork upload expired. Please choose the file again.');

        return $decoded;
    }

    /** @param array<string,mixed> $manifest */
    private function writeManifest(array $manifest): void
    {
        $encoded = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $written = $this->disk()->put($this->basePath($manifest).'/manifest.json', $encoded);
        abort_unless($written, 500, 'The artwork upload state could not be saved.');
    }

    /** @param array<string,mixed> $manifest */
    private function materializeManifest(array $manifest): string
    {
        $directory = storage_path('app/artwork-upload-materialized');
        File::ensureDirectoryExists($directory, 0700, true);
        $temporary = tempnam($directory, 'artwork-');
        if ($temporary === false) {
            throw new RuntimeException('A temporary artwork file could not be created.');
        }

        $target = fopen($temporary, 'wb');
        if ($target === false) {
            @unlink($temporary);
            throw new RuntimeException('A temporary artwork file could not be opened.');
        }

        try {
            $disk = $this->disk();
            $configuredChunkBytes = max(1, (int) ($manifest['chunk_bytes'] ?? $this->chunkBytes()));
            $chunkCount = max(1, (int) ($manifest['chunk_count'] ?? ceil(((int) ($manifest['size'] ?? 0)) / $configuredChunkBytes)));
            $writtenBytes = 0;
            for ($index = 0; $index < $chunkCount; $index++) {
                $chunkPath = $this->basePath($manifest).'/chunks/'.sprintf('%06d.part', $index);
                abort_unless($disk->exists($chunkPath), 422, 'A staged artwork chunk is missing. Please upload the file again.');
                $stream = $disk->readStream($chunkPath);
                abort_if($stream === false, 500, 'A staged artwork chunk could not be read.');
                try {
                    $copied = stream_copy_to_stream($stream, $target);
                    abort_if($copied === false, 500, 'A staged artwork chunk could not be assembled.');
                    $writtenBytes += (int) $copied;
                } finally {
                    if (is_resource($stream)) fclose($stream);
                }
            }
        } catch (\Throwable $exception) {
            fclose($target);
            @unlink($temporary);
            throw $exception;
        }
        fclose($target);

        $expectedBytes = (int) ($manifest['size'] ?? 0);
        if ($writtenBytes !== $expectedBytes || $writtenBytes <= 0 || $writtenBytes > AttachmentUpload::ARTWORK_MAX_BYTES) {
            @unlink($temporary);
            abort(422, 'The assembled artwork file size does not match the original upload. Please upload it again.');
        }

        return $temporary;
    }

    private function purgeExpiredForUser(User $user): void
    {
        $disk = $this->disk();
        $root = self::ROOT.'/'.(int) $user->id;
        try {
            foreach ($disk->directories($root) as $directory) {
                $manifestPath = trim($directory, '/').'/manifest.json';
                if (! $disk->exists($manifestPath)) {
                    $disk->deleteDirectory($directory);
                    continue;
                }
                $manifest = json_decode((string) $disk->get($manifestPath), true);
                if (! is_array($manifest) || $this->isExpired($manifest)) {
                    $disk->deleteDirectory($directory);
                }
            }
        } catch (\Throwable) {
            // Never block a new upload because stale-file cleanup failed.
        }
    }

    /** @param array<string,mixed> $manifest */
    private function isExpired(array $manifest): bool
    {
        $updatedAt = max(0, (int) ($manifest['updated_at'] ?? $manifest['created_at'] ?? 0));
        $ttl = max(1, (int) config('flowtrack.artwork_chunk_upload.retention_hours', 6)) * 3600;
        return $updatedAt <= 0 || $updatedAt < now()->timestamp - $ttl;
    }

    private function safeOriginalName(string $name): string
    {
        $name = trim(str_replace('\\', '/', $name));
        $name = basename($name);
        $name = str_replace("\0", '', $name);
        abort_if($name === '' || mb_strlen($name) > 255, 422, 'Enter a valid artwork file name.');
        return $name;
    }

    /** @param array<string,mixed> $manifest */
    private function basePath(array $manifest): string
    {
        return self::ROOT.'/'.(int) ($manifest['user_id'] ?? 0).'/'.(string) ($manifest['token'] ?? '');
    }

    /** @param array<string,mixed> $manifest */
    private function descriptor(array $manifest): array
    {
        $name = (string) ($manifest['original_name'] ?? 'artwork');
        return [
            'token' => (string) ($manifest['token'] ?? ''),
            'name' => $name,
            'size' => (int) ($manifest['size'] ?? 0),
            'type' => strtoupper((string) pathinfo($name, PATHINFO_EXTENSION)) ?: 'FILE',
            'revision_document_id' => $this->nullableInt($manifest['revision_document_id'] ?? null),
            'complete' => (bool) ($manifest['complete'] ?? false),
        ];
    }

    /** @param array<string,mixed> $manifest */
    private function progressDescriptor(array $manifest): array
    {
        $descriptor = $this->descriptor($manifest);
        $received = (int) ($manifest['received_bytes'] ?? 0);
        $total = max(1, (int) ($manifest['size'] ?? 1));
        return $descriptor + [
            'received_bytes' => $received,
            'progress' => min(100, (int) floor(($received / $total) * 100)),
        ];
    }

    public function chunkBytes(): int
    {
        // Keep each multipart request below 20MB (including form overhead), but
        // use a larger default so 400MB artwork needs far fewer round trips.
        return max(1024 * 1024, min(18 * 1024 * 1024, (int) config('flowtrack.artwork_chunk_upload.chunk_bytes', 15 * 1024 * 1024)));
    }

    public function chunkConcurrency(): int
    {
        // Three workers is the production default. Clamp configuration so one
        // browser cannot consume an excessive number of PHP-FPM workers.
        return max(1, min(6, (int) config('flowtrack.artwork_chunk_upload.concurrency', 3)));
    }

    private function extendArtworkExecutionWindow(): void
    {
        // Materializing and scanning a 400MB design package can legitimately take
        // longer than PHP's common 30-second default, even though the network
        // transfer itself is now split into short requests. This affects only the
        // current Artwork persistence request.
        $seconds = max(60, (int) config('flowtrack.artwork_chunk_upload.persistence_timeout_seconds', 900));
        if (function_exists('set_time_limit')) {
            @set_time_limit($seconds);
        }
    }

    private function disk()
    {
        return Storage::disk((string) config('flowtrack.quarantine_disk', 'flowtrack_quarantine'));
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = (int) $value;
        return $value > 0 ? $value : null;
    }
}
