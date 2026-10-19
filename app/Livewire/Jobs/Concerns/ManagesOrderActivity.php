<?php

namespace App\Livewire\Jobs\Concerns;

use App\Actions\Orders\AddOrderComment;
use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\ClearOrderAttention;
use App\Actions\Orders\SetOrderAttention;
use App\Queries\Orders\VisibleOrderQuery;
use App\Services\AccessControlService;
use App\Services\JobService;
use App\Services\RichTextService;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5 Order UI workflow extracted from the legacy Jobs coordinator.
 *
 * Public method names and parent Livewire state are intentionally preserved so
 * existing Blade bindings, deep links, validation keys and realtime behavior do
 * not change during the incremental decomposition.
 */
trait ManagesOrderActivity
{
    public function openOrderAttentionReason(): void
    {
        abort_unless($this->selectedJobId, 422);
        $job = app(VisibleOrderQuery::class)->base(auth()->user(), $this->selectedJobId);
        abort_if($job->completed_at || in_array((string) $job->status, JobService::INACTIVE_STATUSES, true), 422, 'A completed or inactive Order cannot be flagged for attention.');

        $this->resetValidation('orderAttentionReason');
        $this->orderAttentionReason = (string) ($job->attention_reason ?: '');
        $this->showOrderAttentionModal = true;
    }

    public function closeOrderAttentionReason(): void
    {
        $this->showOrderAttentionModal = false;
        $this->orderAttentionReason = '';
        $this->resetValidation('orderAttentionReason');
    }

    public function saveOrderAttentionReason(): void
    {
        $this->validate([
            'orderAttentionReason' => ['required', 'string', 'max:2000'],
        ], [
            'orderAttentionReason.required' => 'Write why this Order needs attention.',
        ]);

        abort_unless($this->selectedJobId, 422);
        app(SetOrderAttention::class)->handle(
            auth()->user(),
            $this->selectedJobId,
            $this->orderAttentionReason,
        );
        $this->closeOrderAttentionReason();
        $this->jobActivityPage = 1;
        session()->flash('success', 'Attention request saved and added to Order comments.');
    }

    public function clearOrderAttention(): void
    {
        abort_unless($this->selectedJobId, 422);
        app(ClearOrderAttention::class)->handle(auth()->user(), $this->selectedJobId);
        $this->closeOrderAttentionReason();
        $this->jobActivityPage = 1;
        session()->flash('success', 'Order attention flag cleared.');
    }

    public function openOrderCancelModal(): void
    {
        abort_unless($this->selectedJobId, 422);
        $job = app(VisibleOrderQuery::class)->base(auth()->user(), $this->selectedJobId);
        abort_unless(app(AccessControlService::class)->canEditVisibleJob(auth()->user(), $job), 403);
        abort_if($job->completed_at || in_array((string) $job->status, JobService::INACTIVE_STATUSES, true), 422, 'This Order can no longer be cancelled.');
        abort_if((int) ($job->phase?->sequence ?? 999) > 4, 422, 'Orders can only be cancelled through the QC stage.');

        $this->orderCancellationReason = '';
        $this->showOrderCancelModal = true;
        $this->resetValidation('orderCancellationReason');
    }

    public function closeOrderCancelModal(): void
    {
        $this->showOrderCancelModal = false;
        $this->orderCancellationReason = '';
        $this->resetValidation('orderCancellationReason');
    }

    public function confirmOrderCancellation(?string $reason = null): void
    {
        // The visible editor is contenteditable while this hidden Livewire field
        // stores FlowTrack's sanitized rich-text payload. Accepting the value as
        // an optional argument lets the modal wait for pasted image uploads to
        // finish before submitting the cancellation.
        if ($reason !== null) {
            $this->orderCancellationReason = $reason;
        }

        $normalizedReason = app(RichTextService::class)->normalize(
            $this->orderCancellationReason,
            2000, //max 2000 char limit
            'orderCancellationReason',
        );

        if ($normalizedReason === null) {
            throw ValidationException::withMessages([
                'orderCancellationReason' =>
                    'Enter a reason for cancelling this Order.',
            ]);
        }

        $this->orderCancellationReason = $normalizedReason;

        abort_unless($this->selectedJobId, 422);

        app(CancelOrder::class)->handle(
            auth()->user(),
            $this->selectedJobId,
            $normalizedReason,
            true,
        );

        $this->closeOrderCancelModal();

        $this->jobActivityPage = 1;

        session()->flash(
            'success',
            'Order cancelled. The workflow is now blocked.'
        );
    }

    public function setJobActivityTab(string $tab): void
    {
        abort_unless(in_array($tab, ['all','comments','history'], true), 422);
        $this->jobActivityTab = $tab;
        $this->jobActivityPage = 1;
    }

    public function setJobActivityPage(int $page): void
    {
        $this->jobActivityPage = max(1, $page);
    }

    public function addJobComment(): void
    {
        abort_unless($this->selectedJobId, 422);
        $saved = app(AddOrderComment::class)->handle(
            auth()->user(),
            $this->selectedJobId,
            $this->jobComment,
        );
        if (!$saved) return;

        $this->jobComment = '';
        $this->jobActivityPage = 1;
    }
}
