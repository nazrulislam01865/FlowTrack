<?php

use App\Modules\OrderFlow\Business\Services\WorkflowAutomationService;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\WorkspaceMembership;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('flowtrack:doctor', function () {
    $failures = [];
    $requiredColumns = ['flow_job_id', 'workflow_phase_id', 'task_pack_item_id', 'flow_task_id', 'document_category_id'];

    if (! Schema::hasTable('flow_documents') || ! Schema::hasColumns('flow_documents', $requiredColumns)) {
        $failures[] = 'The flow_documents linkage schema is incomplete. Run: php artisan migrate --force';
    }
    if (! Schema::hasTable('flowtrack_sequences')) {
        $failures[] = 'The concurrency-safe identifier table is missing. Run: php artisan migrate --force';
    }

    $disk = (string) config('flowtrack.documents_disk', 'flowtrack_documents');
    $probe = '.health/'.Str::uuid().'.txt';
    try {
        Storage::disk($disk)->put($probe, 'ok');
        if (! Storage::disk($disk)->exists($probe)) {
            $failures[] = "The {$disk} disk did not persist its write probe.";
        }
        Storage::disk($disk)->delete($probe);
    } catch (Throwable $exception) {
        $failures[] = "The {$disk} disk is not writable: {$exception->getMessage()}";
    }

    $toBytes = static function (string $value): int {
        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    };

    $loadedIni = php_ini_loaded_file() ?: 'no loaded php.ini';
    if ($toBytes((string) ini_get('upload_max_filesize')) < 25 * 1024 * 1024) {
        $failures[] = "PHP upload_max_filesize must be at least 25M (currently ".ini_get('upload_max_filesize')."). Loaded CLI configuration: {$loadedIni}";
    }
    if ($toBytes((string) ini_get('post_max_size')) < 30 * 1024 * 1024) {
        $failures[] = "PHP post_max_size must be at least 30M (currently ".ini_get('post_max_size')."). Loaded CLI configuration: {$loadedIni}";
    }

    if ($failures !== []) {
        foreach ($failures as $failure) {
            $this->error($failure);
        }

        $this->line('For macOS/XAMPP run: bash scripts/configure-xampp-upload-limits.sh');
        $this->line('After changing php.ini, restart Apache or restart php artisan serve.');

        return 1;
    }

    $this->info('FlowTrack schema, private document storage, and PHP upload limits are ready.');

    return 0;
})->purpose('Verify document upload schema, storage permissions, and PHP limits');

Artisan::command('flowtrack:sync-workflow-state {--no-auto : Recalculate health and readiness without automatic phase movement}', function () {
    $automation = app(WorkflowAutomationService::class);
    $allowAuto = ! $this->option('no-auto');
    $processed = 0;
    $skipped = 0;

    FlowJob::query()
        ->whereNotNull('current_phase_id')
        ->where('status', '!=', 'completed')
        ->with(['owner', 'coordinator'])
        ->orderBy('id')
        ->chunkById(100, function ($jobs) use ($automation, $allowAuto, &$processed, &$skipped) {
            foreach ($jobs as $job) {
                $actor = $job->coordinator ?: $job->owner;
                if (! $actor) {
                    $actorId = WorkspaceMembership::query()
                        ->where('workspace_id', $job->workspace_id)
                        ->where('status', 'active')
                        ->orderBy('id')
                        ->value('user_id');
                    $actor = $actorId ? \App\Models\User::query()->find($actorId) : null;
                }

                if (! $actor) {
                    $skipped++;
                    continue;
                }

                $automation->synchronize($job, $actor, 'scheduled_sync', $allowAuto);
                $processed++;
            }
        });

    $this->info("Workflow state synchronized for {$processed} Jobs. Skipped: {$skipped}.");
})->purpose('Generate missing current-Phase tasks and recalculate Job health and phase readiness');

Schedule::command('flowtrack:sync-workflow-state --no-auto')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
