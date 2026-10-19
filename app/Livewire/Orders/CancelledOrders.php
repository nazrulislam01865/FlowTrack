<?php

namespace App\Livewire\Orders;

use App\Services\AccessControlService;
use App\Services\CancelledOrderService;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class CancelledOrders extends Component
{
    use WithPagination;

    #[Url(as: 'search')]
    public string $search = '';

    #[Url(as: 'client')]
    public string $clientId = '';

    #[Url(as: 'stage')]
    public string $phaseId = '';

    #[Url(as: 'reason')]
    public string $reason = '';

    #[Url(as: 'cancelled_by')]
    public string $cancelledBy = '';

    #[Url(as: 'from')]
    public string $fromDate = '';

    #[Url(as: 'to')]
    public string $toDate = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->canAccess('jobs.view'), 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, [
            'search',
            'clientId',
            'phaseId',
            'reason',
            'cancelledBy',
            'fromDate',
            'toDate',
        ], true)) {
            $this->resetPage('cancelledPage');
        }
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->clientId = '';
        $this->phaseId = '';
        $this->reason = '';
        $this->cancelledBy = '';
        $this->fromDate = '';
        $this->toDate = '';
        $this->resetPage('cancelledPage');
    }

    public function goToCancelledPage(int $page): void
    {
        $this->setPage(max(1, $page), 'cancelledPage');
    }

    public function render()
    {
        $user = auth()->user();
        $service = app(CancelledOrderService::class);
        $filters = $this->filters();

        $orders = $service->paginate(
            $user,
            $filters,
            CancelledOrderService::PER_PAGE,
        );

        return view('livewire.orders.cancelled-orders', [
            'orders' => $orders,
            'rows' => $service->rows($orders),
            'metrics' => $service->metrics($user, $filters),
            'clientOptions' => $service->clientOptions($user),
            'phaseOptions' => $service->phaseOptions($user),
            'cancellerOptions' => $service->cancellerOptions($user),
            'reasonOptions' => CancelledOrderService::REASON_LABELS,
            'canExport' => app(AccessControlService::class)->can($user, 'reports', 'export'),
            'exportQuery' => array_filter($filters, fn ($value) => $value !== '' && $value !== null),
        ]);
    }

    /** @return array<string,mixed> */
    private function filters(): array
    {
        return [
            'search' => trim($this->search),
            'client_id' => ctype_digit($this->clientId) ? (int) $this->clientId : null,
            'phase_id' => ctype_digit($this->phaseId) ? (int) $this->phaseId : null,
            'reason' => array_key_exists($this->reason, CancelledOrderService::REASON_LABELS) ? $this->reason : '',
            'cancelled_by' => ctype_digit($this->cancelledBy) ? (int) $this->cancelledBy : null,
            'from_date' => trim($this->fromDate),
            'to_date' => trim($this->toDate),
        ];
    }
}
