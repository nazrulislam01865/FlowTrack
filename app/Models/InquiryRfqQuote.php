<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InquiryRfqQuote extends Model
{
    protected $fillable = [
        'invitation_id', 'supplier_contact_name', 'supplier_contact_email', 'supplier_contact_phone',
        'currency', 'freight', 'tooling_cost', 'sample_cost', 'discount', 'tax_status',
        'lead_time_days', 'sample_lead_time_days', 'incoterm', 'shipping_port', 'estimated_delivery_date',
        'validity_days', 'specification_compliance', 'notes', 'supporting_information', 'document_notes',
        'submitted_by_name', 'submitted_by_email', 'submitted_total', 'portal_submission_uuid', 'portal_details',
    ];

    protected function casts(): array
    {
        return [
            'freight' => 'decimal:2',
            'tooling_cost' => 'decimal:2',
            'sample_cost' => 'decimal:2',
            'discount' => 'decimal:2',
            'submitted_total' => 'decimal:2',
            'lead_time_days' => 'integer',
            'sample_lead_time_days' => 'integer',
            'validity_days' => 'integer',
            'estimated_delivery_date' => 'date',
            'supporting_information' => 'array',
            'portal_details' => 'array',
        ];
    }

    public function invitation(): BelongsTo { return $this->belongsTo(InquiryRfqInvitation::class, 'invitation_id'); }
    public function items(): HasMany { return $this->hasMany(InquiryRfqQuoteItem::class, 'quote_id')->orderBy('sort_order'); }
    public function documents(): HasMany { return $this->hasMany(InquiryRfqQuoteDocument::class, 'quote_id')->orderBy('sort_order')->orderBy('id'); }
}
