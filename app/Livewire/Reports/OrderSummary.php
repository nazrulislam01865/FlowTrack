<?php

namespace App\Livewire\Reports;

use App\Services\AccessControlService;
use App\Services\OrderSummaryReportService;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class OrderSummary extends Component
{
    use WithPagination;

    #[Url(as: 'q', history: true, except: '')]
    public string $search = '';

    #[Url(as: 'supplier', history: true, except: '')]
    public string $supplierId = '';

    #[Url(as: 'warehouse', history: true, except: '')]
    public string $warehouse = '';

    #[Url(as: 'urgency', history: true, except: '')]
    public string $urgency = '';

    #[Url(as: 'from', history: true, except: '')]
    public string $fromDate = '';

    #[Url(as: 'to', history: true, except: '')]
    public string $toDate = '';

    #[Url(as: 'quick', history: true, except: 'all')]
    public string $quick = 'all';

    /** @var array<int|string> */
    public array $clientIds = [];

    public function updatedClientIds($value): void
    {
        if (count($this->clientIds) > 1) {
            $this->clientIds = [end($this->clientIds)];
        }
        $this->resetPage();
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'supplierId', 'warehouse', 'urgency', 'fromDate', 'toDate'], true)) {
            $this->resetPage();
        }
    }


    public function applyFilters(): void
    {
        $this->resetPage();
    }

    public function setQuick(string $quick): void
    {
        if (! in_array($quick, ['all', 'urgent', 'awaiting', 'overdue'], true)) $quick = 'all';
        $this->quick = $quick;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'supplierId', 'warehouse', 'urgency', 'fromDate', 'toDate', 'clientIds']);
        $this->quick = 'all';
        $this->resetPage();
    }

    public function clearClientFilter(): void
    {
        $this->clientIds = [];
        $this->resetPage();
    }

    public function goToReportPage(int $page): void
    {
        $this->setPage(max(1, $page));
    }

    public function render()
    {
        $user = auth()->user();
        abort_unless($user && $user->canAccess('reports.view') && $user->canAccess('jobs.view'), 403);

        $service = app(OrderSummaryReportService::class);
        $filters = $this->filters();
        $orders = $service->paginate($user, $filters);

        return view('livewire.reports.order-summary', [
            'orders' => $orders,
            'rows' => $service->rows($orders),
            'counts' => $service->counts($user, $filters),
            'supplierOptions' => $service->supplierOptions(),
            'warehouseOptions' => $service->warehouseOptions($user),
            'clientOptions' => $service->clientOptions($user),
            'canExport' => app(AccessControlService::class)->can($user, 'reports', 'export'),
            'exportQuery' => array_filter(
                $filters,
                fn ($value) => ! ($value === '' || $value === null || $value === 'all' || $value === [])
            ),
        ]);
    }

    /** @return array<string,mixed> */
    private function filters(): array
    {
        return [
            'search' => trim($this->search),
            'supplier_id' => ctype_digit($this->supplierId) ? (int) $this->supplierId : null,
            'warehouse' => trim($this->warehouse),
            'urgency' => in_array($this->urgency, ['Y', 'N'], true) ? $this->urgency : '',
            'from_date' => trim($this->fromDate),
            'to_date' => trim($this->toDate),
            'client_ids' => $this->normalizedClientIds(),
            'quick' => in_array($this->quick, ['all', 'urgent', 'awaiting', 'overdue'], true) ? $this->quick : 'all',
        ];
    }

    /** @return array<int,int> */
    private function normalizedClientIds(): array
    {
        return collect($this->clientIds)
            ->filter(fn ($value) => is_int($value) || (is_string($value) && ctype_digit($value)))
            ->map(fn ($value) => (int) $value)
            ->filter(fn (int $value) => $value > 0)
            ->unique()
            ->values()
            ->all();
    }
}
