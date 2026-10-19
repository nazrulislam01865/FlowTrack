<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

class ShellDataService
{
    public function for(User $user): array
    {
        return Cache::remember($this->key($user->id), now()->addSeconds(60), function () use ($user) {
            return [
                'unread_notifications' => app(NotificationService::class)->unreadCount($user),
                'open_my_work' => $user->canModule('tasks', 'view')
                    ? app(MyWorkService::class)->openTaskCount($user)
                    : 0,
                'cancelled_orders' => $user->canModule('jobs', 'view')
                    ? app(CancelledOrderService::class)->sidebarCount($user)
                    : 0,
            ];
        });
    }

    public function forget(int $userId): void
    {
        Cache::forget($this->key($userId));
    }

    private function key(int $userId): string
    {
        return 'flowtrack:shell:clients-'.app(ClientService::class)->lifecycleVersion()
            .':data-'.app(WorkspaceRefreshService::class)->version()
            .':user:'.$userId;
    }
}
