<?php

namespace App\Queries\Dashboard;

use App\Models\User;
use App\Services\AccessControlService;
use App\Services\Dashboard\DashboardMentionService;

final class DashboardMentionsQuery
{
    public function __construct(
        private readonly DashboardMentionService $mentions,
        private readonly AccessControlService $access,
    ) {
    }

    public function rows(User $actor, string $filter, int $limit, int $clientId, int $departmentId, int $rangeDays, string $search): mixed
    {
        abort_unless($this->access->can($actor, 'dashboard', 'view'), 403);
        return $this->mentions->mentions($actor, $filter, $limit, $clientId, $departmentId, $rangeDays, $search);
    }

    public function unreadCount(User $actor, int $clientId, int $departmentId, int $rangeDays, string $search): int
    {
        abort_unless($this->access->can($actor, 'dashboard', 'view'), 403);
        return (int) $this->mentions->unreadMentionCount($actor, $clientId, $departmentId, $rangeDays, $search);
    }
}
