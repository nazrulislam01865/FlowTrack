<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InquiryRfqInvitation extends Model
{
    protected $fillable = [
        'workspace_id','inquiry_id','supplier_id','invited_by','token_hash','token_cipher',
        'request_message','supplier_details',
        'invited_at','due_at','link_expires_at','email_status','email_tracking_id','reminder_sent_at',
        'auto_reply_enabled','reminder_enabled','reminder_hours_before_due','allow_revision',
        'interest_status','interest_at','quote_status','quote_submitted_at','awarded_at',
        'rejected_at','rejection_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'due_at' => 'datetime',
            'link_expires_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'auto_reply_enabled' => 'boolean',
            'reminder_enabled' => 'boolean',
            'reminder_hours_before_due' => 'integer',
            'allow_revision' => 'boolean',
            'interest_at' => 'datetime',
            'quote_submitted_at' => 'datetime',
            'awarded_at' => 'datetime',
            'rejected_at' => 'datetime',
            'rejection_notified_at' => 'datetime',
        ];
    }

    public function inquiry(): BelongsTo { return $this->belongsTo(Inquiry::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(MasterRecord::class, 'supplier_id'); }
    public function inviter(): BelongsTo { return $this->belongsTo(User::class, 'invited_by'); }
    public function quote(): HasOne { return $this->hasOne(InquiryRfqQuote::class, 'invitation_id'); }

    /** Empty scope means a legacy/global invitation; non-empty means only these products. */
    public function scopedItemIds(): array
    {
        return \Illuminate\Support\Facades\DB::table('inquiry_rfq_invitation_items')
            ->where('invitation_id', $this->id)->pluck('inquiry_item_id')
            ->map(fn ($id): int => (int) $id)->all();
    }

    public function supplierEmail(): string
    {
        return trim((string) data_get($this->supplier?->metadata, 'email'));
    }

    public function supplierContactName(): string
    {
        return trim((string) data_get($this->supplier?->metadata, 'contact_person')) ?: (string) ($this->supplier?->name ?: 'Supplier');
    }
}
