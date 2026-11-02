<?php

namespace App\Jobs;

use App\Services\RemoteAreas\RemoteAreaLookupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/** Rebuild only in queue workers; web requests never construct large indexes. */
final class RebuildRemoteAreaIndex implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 1800;
    public int $uniqueFor = 1800;

    public function __construct(public int $workspaceId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'remote-area-index:'.$this->workspaceId;
    }

    public function uniqueVia(): mixed
    {
        return Cache::store('redis');
    }

    public function handle(RemoteAreaLookupService $lookup): void
    {
        if (! config('remote_area_lookup.enabled')) return;
        $result = $lookup->build($this->workspaceId);
        if (! $result['published']) throw new RuntimeException('Remote Area rules changed during compilation.');
    }
}
