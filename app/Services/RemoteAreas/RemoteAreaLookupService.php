<?php

namespace App\Services\RemoteAreas;

use App\Models\MasterRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/**
 * Prepared Redis index for workspace-specific Remote Area rules.
 *
 * Reads always check the durable DB revision first, so a stale published
 * generation cannot be used even after Redis failures, concurrent builds or
 * process restarts. On any unavailable or partial generation, caller must use
 * the unchanged legacy matcher (never infer "no remote area").
 */
final class RemoteAreaLookupService
{
    private function redis(): mixed
    {
        return Redis::connection((string) config('remote_area_lookup.redis_connection', 'default'));
    }

    private function base(int $workspaceId): string
    {
        return (string) config('remote_area_lookup.key_prefix', 'flowtrack:remote-area:compiled:v1').':workspace:'.$workspaceId;
    }

    private function generation(int $workspaceId, int $revision): string
    {
        return $this->base($workspaceId).':revision:'.$revision;
    }

    private function currentRevision(int $workspaceId): ?int
    {
        $revision = DB::table('remote_area_lookup_versions')->where('workspace_id', $workspaceId)->value('revision');
        return $revision === null ? null : (int) $revision;
    }

    /**
     * @return array{ready:bool,record:?MasterRecord}
     * A complete index miss is different from a missing/corrupt index.
     */
    public function lookup(int $workspaceId, ?string $postalCode): array
    {
        $code = PostalRangeCompiler::key($postalCode);
        if ($code === '') return ['ready' => true, 'record' => null];
        if (! config('remote_area_lookup.enabled')) return ['ready' => false, 'record' => null];

        try {
            $revision = $this->currentRevision($workspaceId);
            if ($revision === null) return ['ready' => false, 'record' => null];
            $redis = $this->redis();
            $published = $redis->get($this->base($workspaceId).':published');
            if (! is_string($published) || ! str_starts_with($published, $revision.'|')) {
                return ['ready' => false, 'record' => null];
            }
            $key = substr($published, strlen((string) $revision) + 1);
            if (! str_starts_with($key, $this->generation($workspaceId, $revision).':build:')) {
                return ['ready' => false, 'record' => null];
            }
            $manifest = $redis->get($key.':manifest');
            $meta = is_string($manifest) ? json_decode($manifest, true) : null;
            if (! is_array($meta) || (int) ($meta['revision'] ?? -1) !== $revision) {
                return ['ready' => false, 'record' => null];
            }
            // The exact hash carries a sentinel, including for workspaces with
            // zero exact rules. An evicted hash must trigger legacy fallback.
            if (! $redis->hget($key.':exact', '__ready__')) {
                return ['ready' => false, 'record' => null];
            }

            $id = $redis->hget($key.':exact', $code);
            if ($id === null || $id === false) {
                $shape = PostalRangeCompiler::shape($code);
                if ($shape !== null && in_array($shape, $meta['shapes'] ?? [], true)) {
                    $rangesKey = $key.':range:'.$shape;
                    if (! $redis->exists($rangesKey)) return ['ready' => false, 'record' => null];
                    // All members share score 0; lexicographical predecessor.
                    // Fixed L/D shape guarantees each start has equal length.
                    $entries = $redis->zrevrangebylex($rangesKey, '['.$code.'~', '-', 0, 1);
                    $member = is_array($entries) ? ($entries[0] ?? null) : null;
                    if ($member !== null) {
                        $parts = explode('|', (string) $member, 3);
                        if (count($parts) !== 3) return ['ready' => false, 'record' => null];
                        if (strcmp($code, $parts[0]) >= 0 && strcmp($code, $parts[1]) <= 0) {
                            $id = $parts[2];
                        }
                    }
                }
            }

            if ($id === null || $id === false) {
                // A concurrent edit may commit after the initial revision read.
                if ($this->currentRevision($workspaceId) !== $revision) return ['ready' => false, 'record' => null];
                return ['ready' => true, 'record' => null];
            }
            if (! ctype_digit((string) $id) || (int) $id < 1) return ['ready' => false, 'record' => null];
            $matched = MasterRecord::query()->forWorkspace($workspaceId)
                ->ofType('remote_area')->active()->find((int) $id);
            // Deleted/deactivated records cannot be displayed or surcharged.
            // A dirty revision will be retried by the legacy path.
            if ($this->currentRevision($workspaceId) !== $revision) {
                return ['ready' => false, 'record' => null];
            }
            return $matched
                ? ['ready' => true, 'record' => $matched]
                : ['ready' => false, 'record' => null];
        } catch (Throwable) {
            // Redis outages and missing migrations must not change pricing.
            return ['ready' => false, 'record' => null];
        }
    }

    /** True only when the current durable revision and all Redis keys exist. */
    public function ready(int $workspaceId): bool
    {
        try {
            $revision = $this->currentRevision($workspaceId);
            if ($revision === null) return false;
            $redis = $this->redis();
            $pointer = $redis->get($this->base($workspaceId).':published');
            if (! is_string($pointer) || ! str_starts_with($pointer, $revision.'|')) return false;
            $key = explode('|', $pointer, 2)[1];
            if (! str_starts_with($key, $this->generation($workspaceId, $revision).':build:')) return false;
            $meta = json_decode((string) $redis->get($key.':manifest'), true);
            if (! is_array($meta) || (int) ($meta['revision'] ?? -1) !== $revision) return false;
            if (! $redis->hget($key.':exact', '__ready__')) return false;
            foreach ($meta['shapes'] ?? [] as $shape) {
                if (! is_string($shape) || ! preg_match('/^[DL]+$/', $shape) || ! $redis->exists($key.':range:'.$shape)) return false;
            }
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Build in CLI/maintenance only. No HTTP request is allowed to call this.
     * @return array{workspace_id:int,revision:int,records:int,exact:int,segments:int,shapes:int,published:bool}
     */
    public function build(int $workspaceId): array
    {
        if ($workspaceId < 1) throw new RuntimeException('Invalid workspace ID.');
        $lock = Cache::store('redis')->lock($this->base($workspaceId).':builder', 1800);
        if (! $lock->get()) throw new RuntimeException('A Remote Area index build is already in progress for this workspace.');
        try {
            return $this->buildLocked($workspaceId);
        } finally {
            $lock->release();
        }
    }

    private function buildLocked(int $workspaceId): array
    {
        DB::table('remote_area_lookup_versions')->insertOrIgnore(['workspace_id' => $workspaceId, 'revision' => 0]);
        $revision = $this->currentRevision($workspaceId);
        if ($revision === null) throw new RuntimeException('Version table is not available. Run the migration.');

        $exact = [];
        $rules = [];
        $ordinal = 0;
        $sourceHash = hash_init('sha256');
        foreach (DB::table('master_records')->where('workspace_id', $workspaceId)
            ->where('type', 'remote_area')->where('status', 'active')->whereNull('deleted_at')
            ->orderBy('sort_order')->orderBy('name')->orderBy('id')
            ->select(['id', 'metadata'])->cursor() as $row) {
            hash_update($sourceHash, (string) $row->id.'|'.(string) $row->metadata."\n");
            $metadata = json_decode((string) ($row->metadata ?? '{}'), true);
            if (! is_array($metadata)) $metadata = [];
            $legacy = PostalRangeCompiler::key((string) ($metadata['postal_code'] ?? ''));
            // Match the MasterRecord accessors exactly, including legacy
            // postal_code fallback when From/To are absent or blank.
            $fromRaw = ($metadata['postal_code_from'] ?? null) ?: ($metadata['postal_code'] ?? '');
            $from = PostalRangeCompiler::key((string) $fromRaw);
            $toRaw = ($metadata['postal_code_to'] ?? null) ?: $fromRaw;
            $to = PostalRangeCompiler::key((string) $toRaw);

            foreach (array_unique([$legacy, $from !== '' && ($to === '' || $from === $to) ? $from : '']) as $code) {
                if ($code !== '' && ! isset($exact[$code])) $exact[$code] = (int) $row->id;
            }
            if ($from !== '' && $to !== '' && $from !== $to) {
                $rules[] = ['from' => $from, 'to' => $to, 'id' => (int) $row->id, 'ordinal' => $ordinal];
            }
            $ordinal++;
        }

        $compiled = PostalRangeCompiler::compile($rules);
        unset($rules);
        $redis = $this->redis();
        $generation = $this->generation($workspaceId, $revision);
        $ttl = max(3600, (int) config('remote_area_lookup.generation_ttl_seconds', 86400));
        $segments = array_sum(array_map('count', $compiled));
        $shapes = array_keys($compiled);
        sort($shapes, SORT_STRING);
        $manifest = [
            'revision' => $revision,
            'records' => $ordinal,
            'source_sha256' => hash_final($sourceHash),
            'exact' => count($exact),
            'segments' => $segments,
            'shapes' => $shapes,
        ];

        // A unique temporary namespace permits concurrent builders without
        // exposing partially written data or overwriting a published index.
        $temporary = $generation.':build:'.bin2hex(random_bytes(8));
        $exactKey = $temporary.':exact';
        $redis->hset($exactKey, '__ready__', '1');
        $redis->expire($exactKey, $ttl);
        foreach (array_chunk($exact, 500, true) as $chunk) $redis->hmset($exactKey, $chunk);
        $redis->expire($exactKey, $ttl);
        foreach ($compiled as $shape => $list) {
            $rangesKey = $temporary.':range:'.$shape;
            foreach (array_chunk($list, 1000) as $batch) {
                $redis->pipeline(static function ($pipeline) use ($rangesKey, $batch): void {
                    foreach ($batch as $segment) {
                        $pipeline->zadd($rangesKey, 0, $segment['start'].'|'.$segment['end'].'|'.$segment['id']);
                    }
                });
            }
            $redis->expire($rangesKey, $ttl);
        }
        // Check actual Redis write totals before publishing. A partial or
        // silently failed HMSET / pipeline must never become authoritative.
        if ((int) $redis->hlen($exactKey) !== count($exact) + 1) {
            throw new RuntimeException('Redis exact-map count verification failed.');
        }
        foreach ($compiled as $shape => $list) {
            if ((int) $redis->zcard($temporary.':range:'.$shape) !== count($list)) {
                throw new RuntimeException('Redis range index count verification failed for '.$shape);
            }
        }
        // Readers only trust the final manifest and published pointer.
        $redis->setex($temporary.':manifest', $ttl, json_encode($manifest, JSON_THROW_ON_ERROR));
        if ($this->currentRevision($workspaceId) !== $revision) {
            return [...$manifest, 'workspace_id' => $workspaceId, 'published' => false];
        }

        // Active generations have no TTL. Otherwise every workspace would
        // silently fall back to the 65k-record legacy scan after one day.
        $redis->persist($exactKey);
        foreach ($shapes as $shape) $redis->persist($temporary.':range:'.$shape);
        $redis->persist($temporary.':manifest');

        // Publish only complete immutable data via a single pointer SET.
        // Readers also require the authoritative DB revision to match.
        $pointer = $this->base($workspaceId).':published';
        $previous = $redis->get($pointer);
        if ($this->currentRevision($workspaceId) !== $revision) {
            return [...$manifest, 'workspace_id' => $workspaceId, 'published' => false];
        }
        if ($redis->set($pointer, $revision.'|'.$temporary) === false) {
            throw new RuntimeException('Redis generation publication failed.');
        }
        $this->retirePrevious($redis, $workspaceId, $previous, $temporary, $ttl);
        return [...$manifest, 'workspace_id' => $workspaceId, 'published' => true];
    }
    private function retirePrevious(mixed $redis, int $workspaceId, mixed $pointer, string $current, int $ttl): void
    {
        if (! is_string($pointer) || ! str_contains($pointer, '|')) return;
        [, $old] = explode('|', $pointer, 2);
        if ($old === $current || ! str_starts_with($old, $this->base($workspaceId).':revision:')
            || ! str_contains($old, ':build:')) return;
        try {
            $meta = json_decode((string) $redis->get($old.':manifest'), true);
            foreach (($meta['shapes'] ?? []) as $shape) {
                if (is_string($shape) && preg_match('/^[DL]+$/', $shape)) $redis->expire($old.':range:'.$shape, $ttl);
            }
            $redis->expire($old.':exact', $ttl);
            $redis->expire($old.':manifest', $ttl);
        } catch (Throwable) {
            // Retired generations may be cleaned manually; never break publish.
        }
    }

}
