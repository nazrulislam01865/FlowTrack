<?php

namespace App\Services;

use App\Models\User;
use App\Services\Dashboard\DashboardReadModelCache;

/**
 * Compatibility facade retained while Phase 12 validates focused dashboard read models.
 * Existing method callers keep the historical API; active Dashboard Livewire screens
 * use the dedicated Query classes under App\Queries\Dashboard.
 */
class DashboardService extends LegacyDashboardService
{
    public function __construct(private readonly DashboardReadModelCache $readCache)
    {
    }

    public function forget(User|int $user): void
    {
        parent::forget($user);
        $this->readCache->forgetUser($user);
    }

    public function forgetMentions(User|int $user): void
    {
        parent::forgetMentions($user);
        $this->readCache->forgetMentions($user);
    }
}
