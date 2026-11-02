<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('task_number_sequences', function (Blueprint $table): void {
            $table->string('scope', 32)->primary();
            $table->unsignedBigInteger('next_value');
        });

        // Existing TSK codes can be higher than task IDs + 301. Read only
        // task_number values (including soft-deleted rows), once on upgrade.
        // A cursor avoids loading the complete historical task table in memory.
        $highestNumber = 300;
        foreach (DB::table('tasks')
            ->where('task_number', 'like', 'TSK-%')
            ->select('task_number')
            ->cursor() as $task) {
            if (preg_match('/^TSK-(\d+)$/', (string) $task->task_number, $match)) {
                $highestNumber = max($highestNumber, (int) $match[1]);
            }
        }

        $highestId = (int) DB::table('tasks')->max('id');
        DB::table('task_number_sequences')->insert([
            'scope' => 'tasks',
            'next_value' => max(301, $highestNumber + 1, $highestId + 301),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('task_number_sequences');
    }
};
