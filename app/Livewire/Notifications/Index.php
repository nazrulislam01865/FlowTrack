<?php

namespace App\Livewire\Notifications;

use App\Livewire\Concerns\UsesPagePlaceholder;
use App\Livewire\Concerns\RefreshesFromWorkspace;

use App\Services\NotificationService;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use RefreshesFromWorkspace;
    use UsesPagePlaceholder;
    use WithPagination;

    public function markAllRead(): void
    {
        app(NotificationService::class)->markAllRead(auth()->user());
        $this->dispatch('flowtrack-unread-count', count: 0);
    }

    public function markRead(int $id): void
    {
        $user = auth()->user();
        $service = app(NotificationService::class);
        $notification = $service->visibleQuery($user)->whereKey($id)->firstOrFail();
        $this->dispatch('flowtrack-unread-count', count: $service->markRead($user, $notification));
    }

    #[On('flowtrack-notification')]
    public function refreshNotifications(): void
    {
        // The next render pulls the new database notification. The Reverb event
        // only tells Livewire that fresh data is available.
    }

    public function render()
    {
        return view('livewire.notifications.index', [
            'notifications' => app(NotificationService::class)->paginate(auth()->user(), 30),
        ]);
    }
}
