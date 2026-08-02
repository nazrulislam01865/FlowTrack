<?php

namespace App\Modules\OrderFlow\Business\Services;

use App\Modules\OrderFlow\Data\Models\Client;
use App\Modules\OrderFlow\Data\Models\FlowDocument;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class IdentifierGenerator
{
    public function client(int $workspaceId): string
    {
        return $this->next(
            Client::class,
            'CL-',
            3,
            "client:workspace:{$workspaceId}",
            fn (Builder $query): Builder => $query->where('workspace_id', $workspaceId),
        );
    }

    public function job(): string
    {
        $prefix = 'JOB-'.now()->format('Y').'-';

        return $this->next(FlowJob::class, $prefix, 5, "job:{$prefix}");
    }

    public function order(): string
    {
        $prefix = 'ORD-'.now()->format('Y').'-';

        return $this->next(FlowJob::class, $prefix, 5, "order:{$prefix}", null, 'order_code');
    }

    public function task(): string
    {
        return $this->next(FlowTask::class, 'TSK-', 6, 'task:TSK');
    }

    public function document(): string
    {
        return $this->next(FlowDocument::class, 'DOC-', 6, 'document:DOC');
    }

    /**
     * Allocate a human-readable identifier under a database row lock. The lock
     * remains held by an outer business transaction, preventing two workers
     * from assigning the same document/task/job code under concurrent load.
     *
     * @param class-string<\Illuminate\Database\Eloquent\Model> $model
     */
    private function next(
        string $model,
        string $prefix,
        int $digits,
        string $sequenceKey,
        ?Closure $scope = null,
        string $column = 'code',
    ): string
    {
        return DB::transaction(function () use ($model, $prefix, $digits, $sequenceKey, $scope, $column): string {
            $query = $model::withTrashed()->where($column, 'like', $prefix.'%');
            if ($scope) {
                $query = $scope($query);
            }

            $last = $query->max($column);
            $existingMaximum = (int) substr((string) $last, -$digits);

            DB::table('flowtrack_sequences')->insertOrIgnore([
                'key' => $sequenceKey,
                'current_value' => $existingMaximum,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $current = (int) DB::table('flowtrack_sequences')
                ->where('key', $sequenceKey)
                ->lockForUpdate()
                ->value('current_value');
            $number = max($current, $existingMaximum) + 1;

            DB::table('flowtrack_sequences')->where('key', $sequenceKey)->update([
                'current_value' => $number,
                'updated_at' => now(),
            ]);

            return $prefix.str_pad((string) $number, $digits, '0', STR_PAD_LEFT);
        }, 3);
    }
}
