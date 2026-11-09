<?php

namespace Tests\Feature;

use App\Models\FlowJob;
use App\Models\User;
use App\Models\WorkflowPhase;
use App\Queries\Orders\OrderWorkflowDisplayQuery;
use App\Services\AccessControlService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/** Minimal isolated SQLite fixture to prove bounded reads and actor scoping. */
class OrderWorkflowDisplayQuerySqlBudgetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('tasks', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('flow_job_id');
            $table->unsignedBigInteger('workflow_phase_id');
            $table->unsignedBigInteger('assignee_id')->nullable();
            $table->unsignedBigInteger('task_pack_task_id')->nullable();
            $table->unsignedBigInteger('document_category_id')->nullable();
            $table->string('title')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->index(['flow_job_id', 'workflow_phase_id']);
        });
        Schema::create('workflow_phases', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('short_name')->nullable();
            $table->integer('sequence')->default(1);
            $table->string('color')->nullable();
        });
        // EXISTS subqueries are part of the bounded card projection. They
        // preserve conditional-task activation without full evidence loading.
        Schema::create('documents', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('task_id')->nullable();
        });
        Schema::create('task_links', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('task_id')->nullable();
        });
        Schema::create('supplier_task_assignments', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('task_id');
        });
        // The display projection eagerly reads assignee names and avatars.
        // Keep this fixture self-contained rather than depending on a migrated users table.
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('profile_image_path')->nullable();
        });
        DB::table('users')->insert([
            ['id' => 11, 'name' => 'Allowed Assignee'],
            ['id' => 12, 'name' => 'Other Assignee'],
        ]);
        DB::table('workflow_phases')->insert(['id' => 10, 'name' => 'Artwork', 'sequence' => 2]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('task_links');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('supplier_task_assignments');
        Schema::dropIfExists('workflow_phases');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    private function read(int $orderId, int $viewerId): array
    {
        $viewer = new User;
        $viewer->id = $viewerId;
        $order = new FlowJob;
        $order->id = $orderId;
        $phase = new WorkflowPhase;
        $phase->id = 10;
        $access = Mockery::mock(AccessControlService::class);
        $access->shouldReceive('applyTaskScope')->once()
            ->andReturnUsing(static fn (Builder $builder, User $actor): Builder => $builder
                ->where('assignee_id', (int) $actor->id));
        $reads = 0;
        DB::listen(static function ($event) use (&$reads): void {
            if (str_starts_with(strtolower(ltrim($event->sql)), 'select')) {
                $reads++;
            }
        });
        $before = $reads;
        $projection = (new OrderWorkflowDisplayQuery($access))
            ->loadForAuthorizedOrder($order, $viewer, collect([$phase]));

        return [$projection, $reads - $before];
    }

    public function test_role_scoped_task_projection_query_count_does_not_grow_with_task_count(): void
    {
        $small = [];
        for ($i = 1; $i <= 5; $i++) {
            $small[] = ['flow_job_id' => 77, 'workflow_phase_id' => 10, 'assignee_id' => 11, 'title' => 'Small '.$i];
        }
        DB::table('tasks')->insert($small);
        [$first, $smallSelects] = $this->read(77, 11);
        $this->assertCount(5, $first->tasks);
        $this->assertSame(5, $first->tasksById->count());

        $large = [];
        for ($i = 1; $i <= 120; $i++) {
            $large[] = [
                'flow_job_id' => 88,
                'workflow_phase_id' => 10,
                'assignee_id' => $i <= 100 ? 11 : 12,
                'title' => 'Large '.$i,
            ];
        }
        DB::table('tasks')->insert($large);
        [$second, $largeSelects] = $this->read(88, 11);
        $this->assertCount(100, $second->tasks);
        $this->assertSame(100, $second->tasksByPhase->get(10)->count());
        $this->assertSame($smallSelects, $largeSelects);
        // One bounded SELECT per eager-loaded relation is expected. The
        // constant count, not an arbitrary three-SELECT cap, prevents N+1.
        $this->assertLessThanOrEqual(6, $largeSelects);
        $this->assertNull($second->tasksById->get((int) DB::table('tasks')->where('assignee_id', 12)->value('id')));
    }

    public function test_conditional_evidence_flags_are_scoped_without_loading_document_rows(): void
    {
        $allowed = DB::table('tasks')->insertGetId([
            'flow_job_id' => 77, 'workflow_phase_id' => 10,
            'assignee_id' => 11, 'title' => 'Conditional task',
        ]);
        $other = DB::table('tasks')->insertGetId([
            'flow_job_id' => 77, 'workflow_phase_id' => 10,
            'assignee_id' => 12, 'title' => 'Hidden task',
        ]);
        DB::table('documents')->insert([
            ['task_id' => $allowed], ['task_id' => $other],
        ]);
        DB::table('task_links')->insert(['task_id' => $allowed]);

        [$projection, $reads] = $this->read(77, 11);
        $this->assertCount(1, $projection->tasks);
        $task = $projection->tasks->first();
        $this->assertTrue((bool) $task->getAttribute('documents_exists'));
        $this->assertTrue((bool) $task->getAttribute('links_exists'));
        $this->assertFalse($task->relationLoaded('documents'));
        $this->assertFalse($task->relationLoaded('links'));
        $this->assertFalse($projection->tasksById->has((int) $other));
        $this->assertLessThanOrEqual(6, $reads);
    }
}
