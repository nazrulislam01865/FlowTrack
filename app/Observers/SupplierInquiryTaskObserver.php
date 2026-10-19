<?php
namespace App\Observers;

use App\Models\InquiryTask;
use App\Models\InquiryRfqInvitation;
use App\Services\SupplierBridge\DeliverRfq;

/** Resend the existing RFQ snapshot only when its first task changes. */
final class SupplierInquiryTaskObserver
{
    public function saved(InquiryTask $task): void { $this->changed($task); }
    public function deleted(InquiryTask $task): void { $this->changed($task); }
    public function restored(InquiryTask $task): void { $this->changed($task); }

    private function changed(InquiryTask $task): void
    {
        if ((int) $task->sequence !== 1 || ! config('supplier_bridge.enabled')) return;
        InquiryRfqInvitation::query()->where('inquiry_id', $task->inquiry_id)
            ->whereNotNull('invited_at')->where('email_status', '!=', 'Draft')
            ->select(['id', 'supplier_id'])->cursor()
            ->each(fn (InquiryRfqInvitation $invitation) => app(DeliverRfq::class)->queue($invitation));
    }
}
