<?php

namespace App\Livewire\Inquiries\Concerns;

use App\Models\Inquiry;
use Livewire\Attributes\Renderless;

trait ManagesInquiryFinalDecision
{
    #[Renderless]
    public function updateListStatus(int $inquiryId, string $status): array
    {
        $inquiry = app(\App\Queries\Inquiries\InquiryDetailQuery::class)->find(auth()->user(), $inquiryId);
        $saved = app(\App\Actions\Inquiries\UpdateInquiryStatus::class)->handle($inquiry, $status, auth()->user());
        return ['ok' => true, 'status' => $saved->status, 'tone' => $this->tone($saved->status), 'color' => app(\App\Queries\Inquiries\InquiryDetailQuery::class)->statusColor((string) $saved->status)];
    }

    public function convertInquiryFromList(int $inquiryId): void
    {
        $inquiry = app(\App\Queries\Inquiries\InquiryDetailQuery::class)->find(auth()->user(), $inquiryId);
        $job = app(\App\Actions\Inquiries\ConvertInquiryToOrder::class)->handle($inquiry, auth()->user());
        $this->refreshInquiryListMetrics();
        session()->flash('success', $job->displayOrderNumber().' created from Inquiry.');
    }

    public function markInquiryDeadFromList(int $inquiryId, string $reason): void
    {
        $reason = trim($reason);
        abort_if($reason === '' || mb_strlen($reason) > 255, 422, 'Please provide a closure reason.');

        $inquiry = app(\App\Queries\Inquiries\InquiryDetailQuery::class)->find(auth()->user(), $inquiryId);
        app(\App\Actions\Inquiries\MarkInquiryDead::class)->handle($inquiry, $reason, null, auth()->user());
        $this->refreshInquiryListMetrics();
        session()->flash('success', 'Inquiry closed.');
    }

    public function convertToOrder(): void
    {
        $job = app(\App\Actions\Inquiries\ConvertInquiryToOrder::class)->handle($this->selectedInquiry(), auth()->user());
        $this->refreshInquiryListMetrics();
        session()->flash('success', $job->displayOrderNumber().' created from Inquiry.');
    }

    public function markDead(): void
    {
        $this->validate([
            'deadReason' => ['required', 'string', 'max:255'],
            'deadNote' => ['nullable', 'string', 'max:2000'],
        ]);
        app(\App\Actions\Inquiries\MarkInquiryDead::class)->handle($this->selectedInquiry(), $this->deadReason, $this->deadNote, auth()->user());
        $this->refreshInquiryListMetrics();
        session()->flash('success', 'Inquiry closed.');
    }
}
