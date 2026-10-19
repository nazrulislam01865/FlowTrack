<?php

namespace App\Livewire\Jobs\Concerns;

use App\Actions\Orders\PlaceOrderOnHold;
use App\Actions\Orders\ReleaseOrderHold;
use App\Models\OrderHold;
use App\Queries\Orders\VisibleOrderQuery;
use App\Services\AccessControlService;
use App\Services\JobService;

trait ManagesOrderHold
{
    public function openOrderHoldModal(): void
    {
        abort_unless($this->selectedJobId, 422);

        $job = app(VisibleOrderQuery::class)->base(auth()->user(), (int) $this->selectedJobId);
        abort_unless(app(AccessControlService::class)->canEditVisibleJob(auth()->user(), $job), 403);
        abort_if($job->completed_at || in_array((string) $job->status, JobService::INACTIVE_STATUSES, true), 422, 'A completed or inactive Order cannot be placed on hold.');
        abort_if($job->activeHold, 422, 'This Order is already on hold.');

        $this->orderHoldFrom = OrderHold::FROM_CLIENT;
        $this->orderHoldReason = '';
        $this->showOrderHoldModal = true;
        $this->resetValidation(['orderHoldFrom', 'orderHoldReason']);
    }

    public function closeOrderHoldModal(): void
    {
        $this->showOrderHoldModal = false;
        $this->orderHoldFrom = OrderHold::FROM_CLIENT;
        $this->orderHoldReason = '';
        $this->resetValidation(['orderHoldFrom', 'orderHoldReason']);
    }

    public function placeOrderOnHold(): void
    {
        $this->validate([
            'orderHoldFrom' => ['required', 'string', 'in:client,internal,management'],
            'orderHoldReason' => ['nullable', 'string', 'max:500'],
        ], [
            'orderHoldFrom.required' => 'Select where the hold is coming from.',
        ]);

        abort_unless($this->selectedJobId, 422);

        app(PlaceOrderOnHold::class)->handle(
            auth()->user(),
            (int) $this->selectedJobId,
            $this->orderHoldFrom,
            $this->orderHoldReason,
        );

        $this->closeOrderHoldModal();
        $this->jobActivityPage = 1;
        // Update the page-level Alpine guard immediately, then explicitly notify
        // the isolated Workflow child. Livewire browser events bubble upward, so
        // a parent dispatch is not received by a nested isolated component unless
        // it is targeted. Without this targeted refresh, the task rows keep the
        // pre-hold controls until a full browser refresh.
        $this->dispatch('flowtrack:order-hold-state', orderId: (int) $this->selectedJobId, held: true);
        $this->dispatch('order-hold-runtime-changed', orderId: (int) $this->selectedJobId, held: true)
            ->to(component: \App\Livewire\Jobs\OrderWorkflowSection::class);
        session()->flash('success', 'Order placed on hold.');
    }

    public function releaseOrderHold(): void
    {
        abort_unless($this->selectedJobId, 422);

        app(ReleaseOrderHold::class)->handle(
            auth()->user(),
            (int) $this->selectedJobId,
        );

        $this->jobActivityPage = 1;
        // Keep the page guard and isolated Workflow child in the same hold
        // state without requiring a browser refresh.
        $this->dispatch('flowtrack:order-hold-state', orderId: (int) $this->selectedJobId, held: false);
        $this->dispatch('order-hold-runtime-changed', orderId: (int) $this->selectedJobId, held: false)
            ->to(component: \App\Livewire\Jobs\OrderWorkflowSection::class);
        session()->flash('success', 'Order hold released.');
    }
}
