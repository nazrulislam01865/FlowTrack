<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'flow_job_id',
        'invoice_id',
        'sequence',
        'payment_number',
        'payment_date',
        'method',
        'amount',
        'reference',
        'notes',
        'recorded_by',
        'received_account',
        'receipt_path',
        'receipt_name',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function job(): BelongsTo { return $this->belongsTo(FlowJob::class, 'flow_job_id'); }
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
}
