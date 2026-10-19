<?php

namespace App\Services\Dashboard;

use App\Services\LegacyDashboardService;

final class DashboardMentionService
{
    public function __construct(private readonly LegacyDashboardService $legacy)
    {
    }

    public function mentions(mixed ...$arguments): mixed
    {
        return $this->legacy->mentions(...$arguments);
    }

    public function unreadMentionCount(mixed ...$arguments): mixed
    {
        return $this->legacy->unreadMentionCount(...$arguments);
    }

    public function markAllMentionsRead(mixed ...$arguments): mixed
    {
        return $this->legacy->markAllMentionsRead(...$arguments);
    }

    public function markAllCommentMentionsRead(mixed ...$arguments): mixed
    {
        return $this->legacy->markAllCommentMentionsRead(...$arguments);
    }

    public function forgetMentions(mixed ...$arguments): mixed
    {
        return $this->legacy->forgetMentions(...$arguments);
    }

}
