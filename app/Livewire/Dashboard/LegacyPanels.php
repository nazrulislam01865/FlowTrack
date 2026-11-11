<?php

namespace App\Livewire\Dashboard;

use App\Services\AccessControlService;
use Livewire\Attributes\Reactive;

/** Existing dashboard panels retained unchanged until Release B isolation. */
class LegacyPanels extends Index
{
    #[Reactive]
    public int $rangeDays = 7;

    #[Reactive]
    public string $clientFilter = '';

    #[Reactive]
    public string $teamFilter = '';

    #[Reactive]
    public string $search = '';

    public function placeholder(): \Illuminate\Contracts\View\View
    {
        return view('livewire.dashboard.legacy-placeholder');
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $actor = auth()->user();
        abort_unless($actor && app(AccessControlService::class)->can($actor, 'dashboard', 'view'), 403);
        $this->validatedDashboardFilters($actor);

        // No duplicate essential summary or seven-stage request here.
        try {
            $data = $this->legacyDashboardData(false);
        } catch (\Illuminate\Database\QueryException $exception) {
            report($exception);
            return view('livewire.dashboard.legacy-error');
        }

        return view('livewire.dashboard.progressive-panels', $data);
    }
}
