<?php

namespace Tests\Feature;

use App\Services\TaskNumberSequenceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderTaskNumberSequenceRegressionTest extends TestCase
{
    public function test_existing_higher_task_numbers_do_not_collide_with_new_reservations(): void
    {
        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('task_number')->unique();
        });

        try {
            DB::table('tasks')->insert([
                ['id' => 1, 'task_number' => 'TSK-13347'],
                ['id' => 2, 'task_number' => 'TSK-00999'],
                ['id' => 3, 'task_number' => 'TSK-MANUAL'],
            ]);

            $migration = require database_path('migrations/2026_11_03_000005_create_task_number_sequence.php');
            $migration->up();

            $allocator = app(TaskNumberSequenceService::class);
            $this->assertSame(['TSK-13348', 'TSK-13349'], $allocator->reserve(2));
            $this->assertSame([], $allocator->reserve(0));
            $this->assertSame(['TSK-13350'], $allocator->reserve(1));
            $this->assertSame(13351, (int) DB::table('task_number_sequences')->where('scope', 'tasks')->value('next_value'));

            // Rolled-back syncs must not permanently consume task numbers.
            DB::beginTransaction();
            try {
                $this->assertSame(['TSK-13351'], $allocator->reserve(1));
            } finally {
                DB::rollBack();
            }
            $this->assertSame(['TSK-13351'], $allocator->reserve(1));
        } finally {
            Schema::dropIfExists('task_number_sequences');
            Schema::dropIfExists('tasks');
        }
    }

    public function test_both_manual_and_generated_order_tasks_use_one_atomic_sequence(): void
    {
        $source = file_get_contents(app_path('Services/LegacyJobService.php'));
        $this->assertStringContainsString('TaskNumberSequenceService::class)->reserve(1)[0]', $source);
        $this->assertStringContainsString('TaskNumberSequenceService::class)->reserve(count($missingTemplateIds))', $source);
        $this->assertStringNotContainsString("Task::withTrashed()->max('id') + 301", $source);
    }
}
