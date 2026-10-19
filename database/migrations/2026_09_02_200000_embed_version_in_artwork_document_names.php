<?php

use App\Support\ArtworkDocumentName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('documents') || ! Schema::hasTable('tasks')) {
            return;
        }

        $hasAutomationKey = Schema::hasTable('task_pack_items')
            && Schema::hasColumn('task_pack_items', 'automation_key')
            && Schema::hasColumn('tasks', 'task_pack_task_id');

        $query = DB::table('documents')
            ->join('tasks', 'tasks.id', '=', 'documents.task_id');

        if ($hasAutomationKey) {
            $query->leftJoin('task_pack_items', 'task_pack_items.id', '=', 'tasks.task_pack_task_id');
        }

        $query->where(function ($where) use ($hasAutomationKey): void {
            if ($hasAutomationKey) {
                $where->where('task_pack_items.automation_key', 'ART_PREPARE_UPLOAD')
                    ->orWhereIn(DB::raw('LOWER(TRIM(tasks.title))'), [
                        'prepare & upload artwork',
                        'prepare and upload artwork',
                    ]);
                return;
            }

            $where->whereIn(DB::raw('LOWER(TRIM(tasks.title))'), [
                'prepare & upload artwork',
                'prepare and upload artwork',
            ]);
        });

        $query
            ->select([
                'documents.id as document_id',
                'documents.name',
                'documents.version',
            ])
            ->orderBy('documents.id')
            ->chunkById(500, function ($documents): void {
                $updates = collect($documents)
                    ->map(function ($document): ?array {
                        $name = ArtworkDocumentName::versioned(
                            (string) $document->name,
                            max(1, (int) $document->version),
                        );

                        if ($name === (string) $document->name) {
                            return null;
                        }

                        return [
                            'id' => (int) $document->document_id,
                            'name' => $name,
                        ];
                    })
                    ->filter()
                    ->values()
                    ->all();

                if ($updates === []) {
                    return;
                }

                $cases = [];
                $bindings = [];
                $ids = [];

                foreach ($updates as $update) {
                    $cases[] = 'WHEN ? THEN ?';
                    $bindings[] = (int) $update['id'];
                    $bindings[] = (string) $update['name'];
                    $ids[] = (int) $update['id'];
                }

                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                DB::update(
                    'UPDATE documents SET name = CASE id '.implode(' ', $cases).' ELSE name END WHERE id IN ('.$placeholders.')',
                    [...$bindings, ...$ids],
                );
            }, 'documents.id', 'document_id');
    }

    public function down(): void
    {
        // The versioned filename is an intentional data normalization. Removing
        // it would make the version invisible in consumers that only use name.
    }
};
