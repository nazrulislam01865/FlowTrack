<?php

namespace App\Console\Commands;

use App\Services\RemoteAreas\RemoteAreaLookupService;
use Illuminate\Console\Command;
use Throwable;

final class BuildRemoteAreaLookup extends Command
{
    protected $signature = 'flowtrack:remote-area-index {workspace : Workspace ID}';
    protected $description = 'Build and publish a versioned Redis Remote Area index outside HTTP requests';

    public function handle(RemoteAreaLookupService $lookup): int
    {
        $workspaceId = (int) $this->argument('workspace');
        if ($workspaceId < 1) {
            $this->error('Workspace must be a positive integer.');
            return self::FAILURE;
        }
        try {
            $result = $lookup->build($workspaceId);
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            if (! $result['published']) {
                $this->error('Master data changed during build. Rerun the command.');
                return self::FAILURE;
            }
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Build failed: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
