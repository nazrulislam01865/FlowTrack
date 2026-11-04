<?php

namespace Tests\Feature;

use App\Models\FlowJob;
use App\Models\WorkflowPhase;
use App\Services\LegacyJobService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/** Focused Release B blocker proof; no existing workflow or project tables are changed. */
class OrderWorkflowReleaseABFastPathTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Keep these tests independent of the application's large migration
        // history. They use only the columns read by the conservative EXISTS.
        Schema::create('task_pack_items', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('task_pack_id');
            $table->boolean('is_required')->nullable();
        });
        Schema::create('tasks', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('flow_job_id');
            $table->unsignedBigInteger('workflow_phase_id');
            $table->unsignedBigInteger('task_pack_task_id')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('task_pack_items');
        parent::tearDown();
    }

    private function job(?int $phaseId = 22, ?int $packId = 5): FlowJob
    {
        $job = new FlowJob;
        $job->id = 73;
        $job->workflow_phase_id = $phaseId;
        $phase = new WorkflowPhase;
        $phase->id = $phaseId;
        $phase->task_pack_id = $packId;
        $job->setRelation('phase', $phase);

        return $job;
    }

    private function blocked(FlowJob $job): bool
    {
        return (new ReflectionMethod(LegacyJobService::class, 'hasGuaranteedOpenRequiredPhaseTask'))
            ->invoke(app(LegacyJobService::class), $job);
    }

    private function insertTask(?int $itemId, string $status = 'Not Started', ?string $completedAt = null, ?string $deletedAt = null): void
    {
        DB::table('tasks')->insert([
            'flow_job_id' => 73,
            'workflow_phase_id' => 22,
            'task_pack_task_id' => $itemId,
            'status' => $status,
            'completed_at' => $completedAt,
            'deleted_at' => $deletedAt,
        ]);
    }

    public function test_uncompleted_required_task_from_current_pack_is_guaranteed_blocker(): void
    {
        DB::table('task_pack_items')->insert(['id' => 1, 'task_pack_id' => 5, 'is_required' => true]);
        $this->insertTask(1);
        $this->assertTrue($this->blocked($this->job()));
    }

    public function test_optional_foreign_pack_and_completed_tasks_do_not_short_circuit_full_gate(): void
    {
        DB::table('task_pack_items')->insert([
            ['id' => 1, 'task_pack_id' => 5, 'is_required' => false],
            ['id' => 2, 'task_pack_id' => 6, 'is_required' => true],
            ['id' => 3, 'task_pack_id' => 5, 'is_required' => true],
        ]);
        $this->insertTask(1);
        $this->insertTask(2);
        $this->insertTask(3, 'Completed', '2026-11-04 12:00:00');
        $this->assertFalse($this->blocked($this->job()));
    }

    public function test_archived_tasks_are_excluded_and_manual_open_tasks_still_block(): void
    {
        DB::table('task_pack_items')->insert(['id' => 1, 'task_pack_id' => 5, 'is_required' => true]);
        $this->insertTask(1, 'Not Started', null, '2026-11-04 12:00:00');
        $this->assertFalse($this->blocked($this->job()));
        $this->insertTask(null);
        $this->assertTrue($this->blocked($this->job()));
    }

    public function test_unbound_phase_cannot_invent_a_blocker(): void
    {
        $this->insertTask(null);
        $this->assertFalse($this->blocked($this->job(null)));
    }

    public function test_legacy_full_gate_and_default_off_flag_are_retained(): void
    {
        $source = file_get_contents(app_path('Services/LegacyJobService.php'));
        $config = file_get_contents(config_path('flowtrack.php'));
        $binding = file_get_contents(app_path('Services/OrderWorkflowBindingService.php'));

        $this->assertStringContainsString("->where('is_required', true)", $source);
        $this->assertStringContainsString('$job = $this->findVisibleBase($actor, $id);', $source);
        $this->assertStringContainsString('$this->loadCanonicalCurrentPhaseTasks($job);', $source);
        $this->assertStringContainsString('JobDetailPresenter::blockers($job)->isEmpty()', $source);
        $this->assertStringContainsString('return $this->completePhase($job, $actor, true);', $source);
        $this->assertStringContainsString("env('FLOWTRACK_WORKFLOW_AUTO_ADVANCE_BLOCKER_FAST_PATH_ENABLED', false)", $config);
        $this->assertStringContainsString('app(OrderWorkflowSetupService::class)->ensureRuntimeMirror($workflowId)', $binding);
    }
}
