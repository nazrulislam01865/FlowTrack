<?php

namespace App\Actions\Dashboard;

use App\Models\User;
use App\Services\AccessControlService;
use App\Services\Dashboard\DashboardMentionService;

final class MarkDashboardMentionsRead
{
    public function __construct(
        private readonly DashboardMentionService $mentions,
        private readonly AccessControlService $access,
    ) {
    }

    public function handle(User $actor, int $clientId, int $departmentId, int $rangeDays, string $search): void
    {
        abort_unless($this->access->can($actor, 'dashboard', 'view'), 403);
        $this->mentions->markAllMentionsRead($actor, $clientId, $departmentId, $rangeDays, $search);
    }
}
