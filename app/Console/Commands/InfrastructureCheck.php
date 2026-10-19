<?php

namespace App\Console\Commands;

use App\Services\Infrastructure\InfrastructureHealthService;
use Illuminate\Console\Command;

final class InfrastructureCheck extends Command
{
    protected $signature = 'flowtrack:infrastructure:check {--prepare-storage : Create/update the shared-storage health sentinel before checking}';
    protected $description = 'Validate FlowTrack database, cache, queue, shared storage and horizontal-scaling configuration';

    public function handle(InfrastructureHealthService $health): int
    {
        if ($this->option('prepare-storage')) {
            $health->prepareStorageSentinel()
                ? $this->info('Storage health sentinel prepared.')
                : $this->warn('Storage health sentinel could not be prepared.');
        }

        $report = $health->report();
        $rows = [];
        foreach ($report['checks'] as $name => $check) {
            $rows[] = [$name, $check['ok'] ? 'PASS' : 'FAIL', $check['message']];
        }
        $this->table(['Check', 'Status', 'Detail'], $rows);

        $db = (array) config('scalability.database');
        $expected = max(1, (int) ($db['expected_web_workers'] ?? 0))
            + max(0, (int) ($db['expected_queue_workers'] ?? 0))
            + max(0, (int) ($db['expected_scheduler_workers'] ?? 0));
        $recommended = $expected + max(0, (int) ($db['connection_reserve'] ?? 0));
        $this->line("Database connection planning: expected application processes={$expected}; recommended DB capacity >= {$recommended} plus administrative/reporting headroom.");

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
