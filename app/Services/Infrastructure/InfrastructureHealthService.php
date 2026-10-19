<?php

namespace App\Services\Infrastructure;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class InfrastructureHealthService
{
    /** @return array{ok:bool,checks:array<string,array{ok:bool,message:string}>} */
    public function report(): array
    {
        $checks = [
            'configuration' => $this->configurationCheck(),
            'database' => $this->databaseCheck(),
            'cache' => $this->cacheCheck(),
        ];

        if ((bool) config('scalability.health.check_queue', true)) {
            $checks['queue'] = $this->queueCheck();
        }

        if ((bool) config('scalability.health.check_storage', true)) {
            $checks['storage'] = $this->storageCheck();
        }

        return [
            'ok' => collect($checks)->every(fn (array $check): bool => $check['ok']),
            'checks' => $checks,
        ];
    }

    public function prepareStorageSentinel(): bool
    {
        $disk = (string) config('flowtrack.document_disk', 'flowtrack_private');
        $path = (string) config('scalability.storage.health_sentinel', 'health/flowtrack-ready.txt');

        try {
            return (bool) Storage::disk($disk)->put($path, 'flowtrack-ready '.now()->toIso8601String()."\n");
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{ok:bool,message:string} */
    private function configurationCheck(): array
    {
        if (! (bool) config('scalability.horizontal', false)) {
            return $this->ok('single-node compatibility profile');
        }

        $issues = [];
        if ((string) config('cache.default') !== 'redis') $issues[] = 'cache is not Redis';
        if ((string) config('session.driver') !== 'redis') $issues[] = 'session is not Redis';
        if ((string) config('queue.default') !== 'redis') $issues[] = 'queue is not Redis';
        if (! (bool) config('reverb.servers.reverb.scaling.enabled', false)) $issues[] = 'Reverb scaling is disabled';

        if ((bool) config('scalability.storage.require_shared_when_horizontal', true)) {
            $privateRoot = (string) config('filesystems.disks.flowtrack_private.root', '');
            $publicRoot = (string) config('filesystems.disks.public.root', '');
            $quarantineRoot = (string) config('filesystems.disks.flowtrack_quarantine.root', '');
            $defaultPrivate = storage_path('app/flowtrack-private');
            $defaultPublic = storage_path('app/public');
            $defaultQuarantine = storage_path('app/flowtrack-quarantine');
            $documentDisk = (string) config('flowtrack.document_disk', 'flowtrack_private');
            $quarantineDisk = (string) config('flowtrack.quarantine_disk', 'flowtrack_quarantine');

            $documentObject = (string) config("filesystems.disks.{$documentDisk}.driver", '') === 's3';
            $quarantineObject = (string) config("filesystems.disks.{$quarantineDisk}.driver", '') === 's3';
            if ($publicRoot === $defaultPublic) $issues[] = 'public media still use node-local storage';
            if (! $documentObject && $privateRoot === $defaultPrivate) $issues[] = 'private documents still use node-local storage';
            if (! $quarantineObject && $quarantineRoot === $defaultQuarantine) $issues[] = 'quarantine still uses node-local storage';
        }

        return $issues === []
            ? $this->ok('horizontal profile is consistent')
            : $this->fail(implode('; ', $issues));
    }

    /** @return array{ok:bool,message:string} */
    private function databaseCheck(): array
    {
        try {
            DB::select('select 1');
            return $this->ok('database reachable');
        } catch (Throwable $exception) {
            return $this->fail('database unavailable: '.$exception->getMessage());
        }
    }

    /** @return array{ok:bool,message:string} */
    private function cacheCheck(): array
    {
        $key = 'flowtrack:health:'.bin2hex(random_bytes(6));
        try {
            Cache::put($key, 'ok', 10);
            $value = Cache::get($key);
            Cache::forget($key);
            return $value === 'ok' ? $this->ok('cache round-trip ok') : $this->fail('cache round-trip mismatch');
        } catch (Throwable $exception) {
            return $this->fail('cache unavailable: '.$exception->getMessage());
        }
    }

    /** @return array{ok:bool,message:string} */
    private function queueCheck(): array
    {
        $queue = (string) (config('scalability.queues.names.0') ?: 'default');
        try {
            $size = Queue::connection((string) config('queue.default'))->size($queue);
            return $this->ok('queue reachable; '.$queue.' depth='.$size);
        } catch (Throwable $exception) {
            return $this->fail('queue unavailable: '.$exception->getMessage());
        }
    }

    /** @return array{ok:bool,message:string} */
    private function storageCheck(): array
    {
        $disk = (string) config('flowtrack.document_disk', 'flowtrack_private');
        $path = (string) config('scalability.storage.health_sentinel', 'health/flowtrack-ready.txt');
        try {
            return Storage::disk($disk)->exists($path)
                ? $this->ok("storage {$disk} reachable")
                : $this->fail("storage sentinel missing on {$disk}");
        } catch (Throwable $exception) {
            return $this->fail('storage unavailable: '.$exception->getMessage());
        }
    }

    /** @return array{ok:bool,message:string} */
    private function ok(string $message): array
    {
        return ['ok' => true, 'message' => $message];
    }

    /** @return array{ok:bool,message:string} */
    private function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}
