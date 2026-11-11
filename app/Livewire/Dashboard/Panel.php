<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Dashboard\Concerns\ReceivesDashboardFilters;
use App\DTOs\Dashboard\DashboardFilterData;
use App\Livewire\Concerns\RefreshesFromWorkspace;
use App\Models\User;
use App\Services\AccessControlService;
use App\Services\FilterOptionService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;

/** Independent dashboard read boundary. All filters remain owned by Index. */
abstract class Panel extends Component
{
    use RefreshesFromWorkspace;
    use ReceivesDashboardFilters;

    public int $rangeDays = 7;

    public string $clientFilter = '';

    public string $teamFilter = '';

    public string $search = '';

    public int $filterGeneration = 0;

    /** A stable component root owns both loading and loaded markup. */
    public bool $dashboardReady = false;

    public function loadDashboardPanel(): void
    {
        if ($this->dashboardReady) {
            $this->skipRender();
            return;
        }

        $this->dashboardReady = true;
    }

    protected abstract function heading(): string;

    protected function placeholderHeight(): int
    {
        return 300;
    }

    public function placeholder(): View
    {
        return view('livewire.dashboard.panel-placeholder', [
            'heading' => $this->heading(), 'height' => $this->placeholderHeight(),
            'kind' => strtolower(class_basename($this)),
        ]);
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
        // Keep the existing realtime contract in Release B; each mounted panel
        // refreshes only its own projection, never the all-section parent query.
    }

    protected function authorizedFilters(): array
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && app(AccessControlService::class)->can($actor, 'dashboard', 'view'), 403);
        abort_unless(in_array($this->rangeDays, [1, 7, 30], true), 422);
        $options = app(FilterOptionService::class);
        $ids = [];
        foreach (['clientFilter' => 'clients', 'teamFilter' => 'departments'] as $property => $type) {
            $value = trim((string) $this->{$property});
            abort_unless($value === '' || (ctype_digit($value) && (int) $value > 0), 422);
            if ($value !== '') {
                $found = $options->selectedOptions($actor, $type, 'dashboard', [$value])
                    ->first(fn ($item) => (string) ($item['id'] ?? '') === $value);
                abort_unless($found, 422, 'That filter option is no longer available.');
            }
            $ids[$property] = (int) ($value === '' ? 0 : $value);
        }

        return [$actor, new DashboardFilterData(
            $ids['clientFilter'], $ids['teamFilter'], $this->rangeDays,
            mb_strtolower(trim($this->search)),
        )];
    }

    protected function filterCollection(
        Collection $rows,
        int $clientId,
        int $departmentId,
        string $query,
        callable $searchFields,
        callable $clientResolver,
        callable $departmentResolver,
    ): Collection {
        // Same order and field semantics as Index::filterCollection() (legacy).
        return $rows
            ->filter(fn ($row) => $clientId <= 0 || (int) ($clientResolver($row) ?? 0) === $clientId)
            ->filter(fn ($row) => $departmentId <= 0 || (int) ($departmentResolver($row) ?? 0) === $departmentId)
            ->filter(function ($row) use ($query, $searchFields): bool {
                if ($query === '') return true;
                $haystack = mb_strtolower(implode(' ', array_filter(array_map(
                    static fn ($value) => trim((string) $value),
                    $searchFields($row)
                ))));
                return str_contains($haystack, $query);
            })
            ->values();
    }

    protected function failed(QueryException $exception): View
    {
        report($exception);
        return view('livewire.dashboard.panel-error', [
            'heading' => $this->heading(), 'height' => $this->placeholderHeight(),
            'kind' => strtolower(class_basename($this)),
        ]);
    }
}
