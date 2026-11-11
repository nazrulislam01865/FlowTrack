<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Dashboard\Concerns\ReceivesDashboardFilters;
use App\Livewire\Concerns\RefreshesFromWorkspace;
use App\Actions\Dashboard\MarkDashboardMentionsRead;
use App\Queries\Dashboard\DashboardMentionsQuery;
use Livewire\Attributes\On;
use Livewire\Component;

class TaggedComments extends Component
{
    use RefreshesFromWorkspace;
    use ReceivesDashboardFilters;
    public string $filter = 'all';

    public int $rangeDays = 7;

    public string $clientFilter = '';

    public string $teamFilter = '';

    public string $search = '';

    public int $filterGeneration = 0;

    public bool $dashboardReady = false;

    public function loadDashboardPanel(): void
    {
        if ($this->dashboardReady) {
            $this->skipRender();
            return;
        }

        $this->dashboardReady = true;
    }

    public function placeholder(): string
    {
        return view('livewire.dashboard.mentions-placeholder')->render();
    }

    public function setFilter(string $filter): void
    {
        abort_unless(in_array($filter, ['all', 'unread', 'orders', 'inquiries'], true), 422);
        $this->filter = $filter;
    }

    public function markAllRead(): void
    {
        app(MarkDashboardMentionsRead::class)->handle(
            auth()->user(),
            max(0, (int) $this->clientFilter),
            max(0, (int) $this->teamFilter),
            $this->rangeDays,
            $this->search,
        );
    }

    #[On('dashboard-scoped-refresh')]
    public function refreshDashboardPanel(): void
    {
        // Livewire re-renders only this mounted, authorized panel; no global
        // dashboard re-query and no mutation of local tab or pagination state.
    }

    #[On('flowtrack-notification')]
    public function refreshRealtime(): void
    {
        // A normal Livewire re-render is enough. NotificationService clears the
        // recipient's dashboard caches before the browser receives this event.
    }

    public function render()
    {
        if (! $this->dashboardReady) return view('livewire.dashboard.mentions-placeholder');
        $service = app(DashboardMentionsQuery::class);
        $clientId = max(0, (int) $this->clientFilter);
        $departmentId = max(0, (int) $this->teamFilter);

        // Apply both the local mention tab and the parent dashboard controls in SQL
        // before LIMIT. This keeps the feed synchronized with Today/7 days/30 days,
        // Client, Team and Search instead of behaving like an isolated widget.
        $mentions = $service->rows(
            auth()->user(),
            $this->filter,
            4,
            $clientId,
            $departmentId,
            $this->rangeDays,
            $this->search,
        );

        return view('livewire.dashboard.tagged-comments', [
            'mentions' => $mentions,
            'unreadMentionCount' => $service->unreadCount(
                auth()->user(),
                $clientId,
                $departmentId,
                $this->rangeDays,
                $this->search,
            ),
        ]);
    }
}
