<?php

namespace Tests\Feature;

use App\Models\MasterRecord;
use App\Services\MasterDataService;
use App\Services\RemoteAreas\RemoteAreaLookupService;
use App\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use Throwable;

/**
 * Explicit integration opt-in: requires a disposable, isolated Redis database.
 * Run with FLOWTRACK_REMOTE_AREA_INDEX_TESTS=1 on local/staging only.
 */
class RemoteAreaCompiledLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_compiled_matches_legacy_and_revisions_invalidate_without_stale_results(): void
    {
        if (getenv('FLOWTRACK_REMOTE_AREA_INDEX_TESTS') !== '1') {
            $this->markTestSkipped('Requires an isolated Redis test instance and explicit opt-in.');
        }
        try {
            Redis::connection()->ping();
        } catch (Throwable) {
            $this->markTestSkipped('Redis unavailable.');
        }
        config()->set('remote_area_lookup.enabled', true);
        $workspaceId = app(WorkspaceContext::class)->id();
        $range = MasterRecord::query()->create([
            'workspace_id' => $workspaceId, 'type' => 'remote_area', 'code' => 'RMA-RANGE',
            'name' => 'Full zone', 'status' => 'active', 'sort_order' => 1,
            'metadata' => ['postal_code_from' => '00000', 'postal_code_to' => '09999', 'extra_charge' => 25.00],
        ]);
        $overlap = MasterRecord::query()->create([
            'workspace_id' => $workspaceId, 'type' => 'remote_area', 'code' => 'RMA-OVERLAP',
            'name' => 'Overlapping zone', 'status' => 'active', 'sort_order' => 2,
            'metadata' => ['postal_code_from' => '05000', 'postal_code_to' => '15000'],
        ]);
        $exact = MasterRecord::query()->create([
            'workspace_id' => $workspaceId, 'type' => 'remote_area', 'code' => 'RMA-EXACT',
            'name' => 'Exception', 'status' => 'active', 'sort_order' => 50,
            'metadata' => ['postal_code' => '05555', 'extra_charge' => 42.00],
        ]);
        $lookup = app(RemoteAreaLookupService::class);
        self::assertTrue($lookup->build($workspaceId)['published']);
        self::assertTrue($lookup->ready($workspaceId));
        $service = app(MasterDataService::class);
        foreach (['00000', '04999', '05000', '05555', '09999', '10000', '15000', '15001', ' sw 1a 1aa '] as $postal) {
            config()->set('remote_area_lookup.enabled', false);
            $expected = $service->remoteAreaForPostalCode($postal)?->id;
            config()->set('remote_area_lookup.enabled', true);
            self::assertSame($expected, $service->remoteAreaForPostalCode($postal)?->id, $postal);
        }
        self::assertSame($exact->id, $service->remoteAreaForPostalCode('05555')?->id);
        self::assertSame($range->id, $service->remoteAreaForPostalCode('05000')?->id);
        self::assertSame($overlap->id, $service->remoteAreaForPostalCode('10000')?->id);

        $exact->update(['status' => 'inactive']);
        self::assertFalse($lookup->ready($workspaceId));
        // RefreshDatabase wraps each test in an outer transaction: the
        // observer's afterCommit cache callback cannot execute until commit.
        Cache::forget("flowtrack:master:active:{$workspaceId}:remote_area");
        self::assertSame($range->id, (new MasterDataService())->remoteAreaForPostalCode('05555')?->id);
        self::assertTrue($lookup->build($workspaceId)['published']);
        self::assertTrue($lookup->ready($workspaceId));
        self::assertSame($range->id, app(MasterDataService::class)->remoteAreaForPostalCode('05555')?->id);
    }
}
