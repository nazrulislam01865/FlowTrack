<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Orders\OrderReadService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Read-only representative orders, all visible to one exact user/permission context. */
final class SelectOrderBaselineSamples extends Command
{
    protected $signature = 'flowtrack:order-baseline-samples {--user= : Existing active user ID} {--limit=500 : Maximum eligible orders to evaluate}';
    protected $description = 'Choose small/medium/large Order Details baseline candidates without modifying data';

    public function handle(): int
    {
        $id = filter_var($this->option('user'), FILTER_VALIDATE_INT);
        if (! $id || $id < 1) {
            $this->error('Supply the numeric ID of the SAME active authorized user used for browser benchmarking.');
            return self::FAILURE;
        }
        $user = User::query()->where('is_active', true)->find($id);
        if (! $user) {
            $this->error('No matching active user.');
            return self::FAILURE;
        }
        $limit = max(20, min(2000, (int) $this->option('limit')));
        $visibleIds = app(OrderReadService::class)->visibleQuery($user)
            ->whereRaw('LOWER(flow_jobs.status) NOT IN (?, ?, ?)', ['draft', 'cancelled', 'inactive'])
            ->orderByDesc('flow_jobs.id')->limit($limit)->pluck('flow_jobs.id')->map(fn ($v) => (int) $v)->all();
        if (count($visibleIds) < 3) {
            $this->error('At least 3 non-draft/non-cancelled orders must be visible to the selected user.');
            return self::FAILURE;
        }

        $tasks = DB::table('tasks')->whereNull('deleted_at')->whereIn('flow_job_id', $visibleIds)
            ->groupBy('flow_job_id')->selectRaw('flow_job_id, COUNT(*) as total')->pluck('total', 'flow_job_id');
        $documents = DB::table('documents')->whereIn('flow_job_id', $visibleIds)
            ->groupBy('flow_job_id')->selectRaw('flow_job_id, COUNT(*) as total')->pluck('total', 'flow_job_id');
        $items = DB::table('flow_job_items')->whereIn('flow_job_id', $visibleIds)
            ->groupBy('flow_job_id')->selectRaw('flow_job_id, COUNT(*) as total')->pluck('total', 'flow_job_id');
        $scored = array_map(static fn (int $orderId): array => [
            'id' => $orderId,
            'tasks' => (int) ($tasks[$orderId] ?? 0),
            'documents' => (int) ($documents[$orderId] ?? 0),
            'products' => (int) ($items[$orderId] ?? 0),
            'weight' => (int) ($tasks[$orderId] ?? 0) * 2 + (int) ($documents[$orderId] ?? 0) + (int) ($items[$orderId] ?? 0),
        ], $visibleIds);
        usort($scored, static fn ($a, $b) => [$a['weight'], $a['id']] <=> [$b['weight'], $b['id']]);
        $n = count($scored);
        $selected = [
            'small' => $scored[(int) floor(($n - 1) * 0.10)],
            'medium' => $scored[(int) floor(($n - 1) * 0.50)],
            'large' => $scored[(int) floor(($n - 1) * 0.90)],
        ];
        $this->table(['size', 'order_id', 'tasks', 'documents', 'products', 'weight'],
            array_map(static fn ($label, $row) => [$label, $row['id'], $row['tasks'], $row['documents'], $row['products'], $row['weight']],
                array_keys($selected), array_values($selected)));
        $this->info('Use this same user/session, browser and environment for all three orders and all repeats.');
        if (count(array_unique(array_column($selected, 'id'))) !== 3) {
            $this->warn('The selected order sizes overlap; increase --limit or select a more varied sample.');
        }
        return self::SUCCESS;
    }
}
