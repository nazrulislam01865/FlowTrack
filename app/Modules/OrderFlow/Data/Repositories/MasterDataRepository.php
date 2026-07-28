<?php

namespace App\Modules\OrderFlow\Data\Repositories;

use App\Modules\OrderFlow\Data\Models\MasterRecord;
use Illuminate\Support\Collection;

class MasterDataRepository
{
    public function byType(int $workspaceId, string $type, bool $activeOnly = false): Collection
    {
        return MasterRecord::query()
            ->where('workspace_id', $workspaceId)
            ->where('type', $type)
            ->when($activeOnly, fn ($query) => $query->active())
            ->with('parent')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function grouped(int $workspaceId, array $types): array
    {
        return MasterRecord::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('type', $types)
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->groupBy('type')
            ->map(fn (Collection $records) => $records->values())
            ->all();
    }
}
