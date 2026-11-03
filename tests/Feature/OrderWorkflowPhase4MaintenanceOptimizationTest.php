<?php

namespace Tests\Feature;

use App\Services\MasterDataService;
use App\Services\SetupContext;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class OrderWorkflowPhase4MaintenanceOptimizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No database operations: isolate only the request-local workflow
        // cache contract and use a stable workspace ID.
        $context = Mockery::mock(SetupContext::class);
        $context->shouldReceive('workspaceId')->andReturn(1);
        $this->app->instance(SetupContext::class, $context);
    }

    public function test_opt_in_reuses_flag_rows_only_inside_reconciliation_and_returns_distinct_models(): void
    {
        config()->set('flowtrack.workflow_maintenance_batch_enabled', true);
        Cache::shouldReceive('remember')->times(3)->andReturn([
            ['id' => 123, 'name' => 'Ready', 'type' => 'order_task_status'],
        ]);

        $service = app(MasterDataService::class);
        $service->active('order_task_status'); // outside the batch: legacy read
        $service->withWorkflowFlagSnapshot(function () use ($service): void {
            $first = $service->active('order_task_status')->first();
            $second = $service->active('order_task_status')->first();
            $this->assertSame(123, (int) $first->id);
            $this->assertSame(123, (int) $second->id);
            $this->assertNotSame($first, $second);
        });
        $service->active('order_task_status'); // memo cleared on exit
    }

    public function test_opt_in_clears_workflow_snapshot_after_exception(): void
    {
        config()->set('flowtrack.workflow_maintenance_batch_enabled', true);
        Cache::shouldReceive('remember')->twice()->andReturn([]);
        $service = app(MasterDataService::class);

        try {
            $service->withWorkflowFlagSnapshot(function () use ($service): void {
                $service->active('order_task_status');
                throw new \RuntimeException('abort repair');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('abort repair', $exception->getMessage());
        }

        $service->withWorkflowFlagSnapshot(fn () => $service->active('order_task_status'));
    }

    public function test_master_data_invalidation_clears_the_batch_snapshot(): void
    {
        config()->set('flowtrack.workflow_maintenance_batch_enabled', true);
        Cache::shouldReceive('remember')->twice()->andReturn([]);
        Cache::shouldReceive('forget')->once()->andReturn(true);
        $service = app(MasterDataService::class);

        $service->withWorkflowFlagSnapshot(function () use ($service): void {
            $service->active('order_task_status');
            // Exactly the same private invalidator invoked by Master Data
            // save/delete/toggle; no database setup or admin user required.
            (new \ReflectionMethod($service, 'forgetActiveCache'))
                ->invoke($service, 'order_task_status');
            $service->active('order_task_status');
        });
    }

    public function test_flag_off_preserves_original_cache_reads(): void
    {
        config()->set('flowtrack.workflow_maintenance_batch_enabled', false);
        Cache::shouldReceive('remember')->twice()->andReturn([]);
        $service = app(MasterDataService::class);

        $service->withWorkflowFlagSnapshot(function () use ($service): void {
            $service->active('order_task_status');
            $service->active('order_task_status');
        });
    }

    public function test_repair_uses_batch_reads_without_removing_legacy_fallback_or_business_writes(): void
    {
        $source = (string) file_get_contents(app_path('Services/LegacyJobService.php'));
        $binding = (string) file_get_contents(app_path('Services/OrderWorkflowBindingService.php'));
        $section = (string) file_get_contents(app_path('Livewire/Jobs/OrderWorkflowSection.php'));

        $this->assertStringContainsString("config('flowtrack.workflow_maintenance_batch_enabled', false)", $source);
        $this->assertStringContainsString('$existingTasksByTemplate = Task::query()', $source);
        $this->assertStringContainsString("->unique('task_pack_task_id')", $source);
        $this->assertStringContainsString('$commentedTaskIds = FlowTaskComment::query()', $source);
        $this->assertStringContainsString('$memberIds = FlowJobMember::query()', $source);
        $this->assertStringContainsString('Task::firstOrCreate($taskAttributes, [', $source);
        $this->assertStringContainsString('FlowTaskComment::firstOrCreate(', $source);
        $this->assertStringContainsString('FlowJobMember::firstOrCreate(', $source);
        $this->assertStringContainsString('withWorkflowFlagSnapshot(', $binding);
        $this->assertStringContainsString('if ($this->runtimeMatchesPublishedDefinition($job, $workflow, $phases))', $binding);
        $this->assertStringContainsString('app(OrderArtworkEvidenceService::class)->repair(', $binding);
        $this->assertStringContainsString('app(AutoAdvanceOrder::class)->handle(', $section);
        $this->assertStringContainsString('app(VisibleOrderQuery::class)->', $section);
    }
}
