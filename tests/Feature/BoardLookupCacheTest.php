<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowPhase;
use App\Models\WorkflowTemplate;
use App\Services\BoardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use Tests\TestCase;

class BoardLookupCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_board_lookups_cache_only_scalar_rows_and_return_property_accessible_objects(): void
    {
        $user = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $client = Client::create(['name' => 'Cache Safe Client', 'code' => 'CACHE-SAFE', 'is_active' => true]);
        $workflow = WorkflowTemplate::create([
            'workspace_id' => 1,
            'name' => 'Cache Safe Workflow',
            'code' => 'CACHE-SAFE-WF',
            'applies_to' => 'orders',
            'client_availability' => 'all',
            'is_active' => true,
            'is_default' => true,
            'version' => 1,
        ]);
        $runtimeWorkflow = Workflow::create([
            'id' => $workflow->id,
            'name' => $workflow->name,
            'slug' => 'cache-safe-workflow-runtime',
            'is_active' => true,
        ]);
        $phase = WorkflowPhase::create([
            'workflow_id' => $runtimeWorkflow->id,
            'workflow_template_id' => $workflow->id,
            'sequence' => 1,
            'name' => 'Request',
            'short_name' => 'Request',
            'allow_job_start' => true,
            'is_active' => true,
        ]);

        $service = app(BoardService::class);
        $lookups = $service->lookups($user);
        $phases = $service->phases($workflow->id);

        $this->assertSame($client->id, $lookups['clients']->first()->id);
        $this->assertSame('Cache Safe Client', $lookups['clients']->first()->name);
        $this->assertSame($user->id, $lookups['users']->first()->id);
        $this->assertSame($workflow->id, $lookups['workflows']->first()->id);
        $this->assertSame($phase->id, $phases->first()->id);

        $cachedLookups = Cache::get($this->lookupKey($service, $user->id));
        $cachedWorkflows = Cache::get(BoardService::workflowOptionsCacheKey(1));
        $cachedPhases = Cache::get(BoardService::workflowPhaseCacheKey($workflow->id));

        $this->assertIsArray($cachedLookups['clients'][0]);
        $this->assertIsArray($cachedLookups['users'][0]);
        $this->assertIsArray($cachedWorkflows[0]);
        $this->assertIsArray($cachedPhases[0]);
        $this->assertNotInstanceOf(Client::class, $cachedLookups['clients'][0]);
        $this->assertNotInstanceOf(WorkflowTemplate::class, $cachedWorkflows[0]);
        $this->assertNotInstanceOf(WorkflowPhase::class, $cachedPhases[0]);
    }

    public function test_invalid_current_lookup_cache_is_rebuilt_instead_of_reaching_blade(): void
    {
        $user = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $client = Client::create(['name' => 'Recovered Client', 'code' => 'RECOVERED', 'is_active' => true]);
        $service = app(BoardService::class);
        $key = $this->lookupKey($service, $user->id);

        Cache::put($key, [
            'clients' => ['legacy-serialized-value'],
            'users' => ['legacy-serialized-value'],
        ], now()->addMinutes(3));

        $lookups = $service->lookups($user, false);

        $this->assertSame($client->id, $lookups['clients']->first()->id);
        $this->assertSame($user->id, $lookups['users']->first()->id);
        $this->assertIsArray(Cache::get($key)['clients'][0]);
    }

    private function lookupKey(BoardService $service, int $userId): string
    {
        $method = new ReflectionMethod(BoardService::class, 'lookupCacheKey');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $userId);
    }
}
