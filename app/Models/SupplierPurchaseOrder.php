<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPurchaseOrder extends Model
{
    protected $fillable = [
        'flow_job_id', 'task_id', 'supplier_id', 'document_id', 'created_by',
        'po_number', 'batch_uuid', 'mode', 'items_snapshot', 'template_details', 'po_delivery_date', 'revision', 'pdf_path', 'pdf_name',
        'email_status', 'recipient_emails', 'email_subject', 'email_error', 'email_tracking_id', 'sent_at', 'archived_at',
    ];

    protected function casts(): array
    {
        return ['po_delivery_date' => 'date', 'revision' => 'integer', 'items_snapshot' => 'array', 'template_details' => 'array', 'recipient_emails' => 'array', 'sent_at' => 'datetime', 'archived_at' => 'datetime'];
    }

    public function job(): BelongsTo { return $this->belongsTo(FlowJob::class, 'flow_job_id'); }
    public function task(): BelongsTo { return $this->belongsTo(Task::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(MasterRecord::class); }
    public function document(): BelongsTo { return $this->belongsTo(Document::class); }
}
