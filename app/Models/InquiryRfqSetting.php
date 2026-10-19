<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InquiryRfqSetting extends Model
{
    protected $fillable = [
        'workspace_id',
        'inquiry_id',
        'special_note',
        'supplier_details',
        'default_due_at',
        'link_validity_hours',
        'auto_reply_enabled',
        'reminder_enabled',
        'reminder_hours_before_due',
        'allow_revision',
        'award_email_enabled',
        'not_selected_email_enabled',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'default_due_at' => 'datetime',
            'link_validity_hours' => 'integer',
            'auto_reply_enabled' => 'boolean',
            'reminder_enabled' => 'boolean',
            'reminder_hours_before_due' => 'integer',
            'allow_revision' => 'boolean',
            'award_email_enabled' => 'boolean',
            'not_selected_email_enabled' => 'boolean',
        ];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
