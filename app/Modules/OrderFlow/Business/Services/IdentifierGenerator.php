<?php

namespace App\Modules\OrderFlow\Business\Services;

use App\Modules\OrderFlow\Data\Models\Client;
use App\Modules\OrderFlow\Data\Models\FlowDocument;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowTask;

class IdentifierGenerator
{
    public function client(int $workspaceId): string
    {
        $codes = Client::withTrashed()
            ->where('workspace_id', $workspaceId)
            ->where('code', 'like', 'CL-%')
            ->pluck('code');

        $number = $codes
            ->map(fn (string $code): int => preg_match('/^CL-(\d+)$/', $code, $matches) ? (int) $matches[1] : 0)
            ->max() + 1;

        return 'CL-'.str_pad((string) max(1, $number), 3, '0', STR_PAD_LEFT);
    }

    public function job(): string
    {
        return $this->next(FlowJob::class, 'JOB-'.now()->format('Y').'-', 5);
    }

    public function order(): string
    {
        $prefix = 'ORD-'.now()->format('Y').'-';
        $last = FlowJob::withTrashed()->whereNotNull('order_code')->where('order_code', 'like', $prefix.'%')->max('order_code');

        return $prefix.str_pad((string) (((int) substr((string) $last, -5)) + 1), 5, '0', STR_PAD_LEFT);
    }

    public function task(): string
    {
        return $this->next(FlowTask::class, 'TSK-', 6);
    }

    public function document(): string
    {
        return $this->next(FlowDocument::class, 'DOC-', 6);
    }

    /** @param class-string<\Illuminate\Database\Eloquent\Model> $model */
    private function next(string $model, string $prefix, int $digits): string
    {
        $last = $model::withTrashed()->where('code', 'like', $prefix.'%')->max('code');
        $number = ((int) substr((string) $last, -$digits)) + 1;

        return $prefix.str_pad((string) $number, $digits, '0', STR_PAD_LEFT);
    }
}
