<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class InquiryDocument extends Model { protected $fillable = ['inquiry_id','inquiry_task_id','uploaded_by','name','path','mime_type','size','note']; protected static function booted(): void
{
    $refresh = static function (self $document): void {
        if ($document->inquiry_task_id !== null || ! config('supplier_bridge.enabled')) return;
        \App\Models\InquiryRfqInvitation::query()
            ->where('inquiry_id', $document->inquiry_id)->whereNotNull('invited_at')
            ->where('email_status', '!=', 'Draft')
            ->limit(25)->get(['id', 'supplier_id'])->each(
                fn ($invitation) => app(\App\Services\SupplierBridge\DeliverRfq::class)->queue($invitation),
            );
    };
    static::created($refresh);
    static::updated($refresh);
    static::deleted($refresh);
}
 public function inquiry(): BelongsTo { return $this->belongsTo(Inquiry::class); } public function task(): BelongsTo { return $this->belongsTo(InquiryTask::class, 'inquiry_task_id'); } public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); } }
