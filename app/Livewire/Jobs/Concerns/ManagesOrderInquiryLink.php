<?php

namespace App\Livewire\Jobs\Concerns;

use App\Actions\Orders\LinkOrderInquiry;
use App\Actions\Orders\UnlinkOrderInquiry;
use App\Queries\Inquiries\InquiryDetailQuery;
use App\Queries\Orders\VisibleOrderQuery;
use App\Services\AccessControlService;
use Throwable;

/**
 * Order <-> Inquiry linking workflow.
 *
 * One Order may contain multiple linked Inquiries. Each Inquiry still belongs
 * to at most one Order, preserving the existing Inquiry conversion model.
 */
trait ManagesOrderInquiryLink
{
    public function updatedInquirySearch(): void
    {
        $this->selectedLinkInquiryId = null;
        $this->showInquiryLinkConfirm = false;
        $this->resetValidation('inquiryLink');
    }

    public function clearInquirySearch(): void
    {
        $this->inquirySearch = '';
        $this->selectedLinkInquiryId = null;
        $this->showInquiryLinkConfirm = false;
        $this->resetValidation('inquiryLink');
    }

    public function selectInquiryForLink(int $inquiryId): void
    {
        abort_unless($this->selectedJobId && $this->detailTab === 'inquiry', 422);

        $user = auth()->user();
        $job = app(VisibleOrderQuery::class)->base($user, $this->selectedJobId);
        $access = app(AccessControlService::class);
        abort_unless($access->can($user, 'jobs', 'link') && $access->canEditVisibleJob($user, $job), 403);

        $inquiry = app(InquiryDetailQuery::class)->find(
            $user,
            $inquiryId,
            [
                'linkedOrders:id,job_number,order_number',
                'sourceOrder:id,source_inquiry_id,job_number,order_number',
                'convertedJob:id,job_number,order_number',
            ],
        );

        $linkedOrder = $inquiry->linkedOrders->first()
            ?: $inquiry->sourceOrder
            ?: $inquiry->convertedJob;

        if ($linkedOrder) {
            abort_if((int) $linkedOrder->id === (int) $job->id, 409, 'This Inquiry is already linked to this Order.');
            abort(409, 'This Inquiry is already linked to another Order.');
        }

        abort_if((string) $inquiry->result === 'dead', 422, 'A closed Inquiry cannot be linked.');

        $this->selectedLinkInquiryId = $inquiry->id;
        $this->showInquiryLinkConfirm = false;
        $this->resetValidation('inquiryLink');
    }

    public function openInquiryLinkConfirm(): void
    {
        abort_unless($this->selectedJobId && $this->selectedLinkInquiryId, 422);
        $this->showInquiryLinkConfirm = true;
        $this->resetValidation('inquiryLink');
    }

    public function closeInquiryLinkConfirm(): void
    {
        $this->showInquiryLinkConfirm = false;
    }

    public function confirmInquiryLink(): void
    {
        abort_unless($this->selectedJobId && $this->selectedLinkInquiryId, 422);

        try {
            $user = auth()->user();
            app(LinkOrderInquiry::class)->handle($user, $this->selectedJobId, $this->selectedLinkInquiryId);

            $this->showInquiryLinkConfirm = false;
            $this->selectedLinkInquiryId = null;
            $this->inquirySearch = '';
            $this->resetValidation('inquiryLink');
            session()->flash('success', 'Inquiry linked successfully. You can link another Inquiry to the same Order.');
        } catch (Throwable $exception) {
            report($exception);
            $this->showInquiryLinkConfirm = false;
            $message = trim($exception->getMessage());
            $this->addError('inquiryLink', $message !== '' ? $message : 'The Inquiry could not be linked. Please try again.');
        }
    }

    public function openInquiryUnlinkConfirm(int $inquiryId): void
    {
        abort_unless($this->selectedJobId && $this->detailTab === 'inquiry', 422);

        $user = auth()->user();
        $job = app(VisibleOrderQuery::class)->base($user, $this->selectedJobId);
        $access = app(AccessControlService::class);
        abort_unless($access->can($user, 'jobs', 'link') && $access->canEditVisibleJob($user, $job), 403);

        $linked = $job->linkedInquiries()->whereKey($inquiryId)->exists()
            || (int) ($job->source_inquiry_id ?? 0) === $inquiryId;
        abort_unless($linked, 409, 'This Inquiry is not linked to this Order.');

        $this->unlinkInquiryId = $inquiryId;
        $this->showInquiryUnlinkConfirm = true;
        $this->resetValidation('inquiryLink');
    }

    public function closeInquiryUnlinkConfirm(): void
    {
        $this->showInquiryUnlinkConfirm = false;
        $this->unlinkInquiryId = null;
    }

    public function confirmInquiryUnlink(): void
    {
        abort_unless($this->selectedJobId && $this->unlinkInquiryId, 422);

        try {
            $user = auth()->user();
            app(UnlinkOrderInquiry::class)->handle($user, $this->selectedJobId, $this->unlinkInquiryId);

            $this->showInquiryUnlinkConfirm = false;
            $this->unlinkInquiryId = null;
            $this->selectedLinkInquiryId = null;
            $this->resetValidation('inquiryLink');
            session()->flash('success', 'Inquiry unlinked and activity recorded.');
        } catch (Throwable $exception) {
            report($exception);
            $this->showInquiryUnlinkConfirm = false;
            $this->unlinkInquiryId = null;
            $message = trim($exception->getMessage());
            $this->addError('inquiryLink', $message !== '' ? $message : 'The Inquiry could not be unlinked. Please try again.');
        }
    }
}
