<?php

namespace App\Modules\OrderFlow\Business\Services;

use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Data\Models\MasterRecord;
use App\Modules\OrderFlow\Data\Models\TaskPack;
use Illuminate\Database\Eloquent\Builder;

class ConfigurationCodeGenerator
{
    /**
     * Generate the next immutable code for a master-data section.
     *
     * Existing legacy codes are respected when they contain a numeric suffix.
     * Sections whose older records have character-only codes continue from the
     * current record count, so the first generated code never starts at 001
     * when records already exist.
     */
    public function master(MasterDataType $type, int $workspaceId): string
    {
        $query = MasterRecord::withTrashed()
            ->where('workspace_id', $workspaceId)
            ->where('type', $type->value);

        return $this->next($query, $type->prefix());
    }

    public function taskPack(int $workspaceId): string
    {
        $query = TaskPack::query()->where('workspace_id', $workspaceId);

        return $this->next($query, 'TPK-');
    }

    /** @param Builder<MasterRecord>|Builder<TaskPack> $query */
    private function next(Builder $query, string $prefix): string
    {
        $codes = $query->pluck('code');
        $highestSuffix = $codes
            ->map(function (?string $code): int {
                preg_match('/(\d+)$/', (string) $code, $matches);

                return isset($matches[1]) ? (int) $matches[1] : 0;
            })
            ->max() ?? 0;

        $number = max($codes->count(), $highestSuffix) + 1;

        do {
            $code = $prefix.str_pad((string) $number, 3, '0', STR_PAD_LEFT);
            $number++;
        } while ((clone $query)->where('code', $code)->exists());

        return $code;
    }
}
