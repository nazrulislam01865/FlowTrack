<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class FlowJob extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'job_number',
        'order_number',
        'client_id',
        'workflow_id',
        'workflow_phase_id',
        'started_from_phase_id',
        'owner_id',
        'coordinator_id',
        'title',
        'product',
        'category',
        'quantity',
        'commercial_value',
        'currency',
        'status',
        'health',
        'priority',
        'progress',
        'delivery_date',
        'description',
        'next_action',
        'start_handling',
        'start_reason',
        'needs_attention',
        'completed_at',
        'source_workflow_id',
        'source_workflow_phase_id',
        'source_inquiry_id',
        'received_date',
        'supplier_id',
        'warehouse',
        'supplier_instruction',
        'source_row_id',
        'import_profile',
        'bulk_import_id',
        'created_by',
        'is_repeat_order',
        'repeat_order_number',
        'estimated_delivery_date',
        'supplier_delivery_date',
        'production_urgency_ids',
        'shipment_method_ids',
        'shipment_urgency_ids',
        'allow_multiple_shipments',
        'shipment_address_mode',
        'notes',
        'order_flag_id',
        'attention_requested',
        'attention_reason',
        'attention_by',
        'attention_at',
        'cancellation_reason',
        'cancelled_at',
        'cancelled_by',
        'shipping_address',
        'shipping_contact_type',
        'shipping_contact_name',
        'shipping_phone_country_code',
        'shipping_phone',
        'shipping_postal_code',
        'shipping_source_address_id',
        'tracking_token',
        'tracking_token_created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (FlowJob $job) {
            if (! (bool) config('flowtrack.order_tracking_enabled', false)) {
                return;
            }

            if (empty($job->tracking_token)) {
                $job->tracking_token = \Illuminate\Support\Str::random(32);
                $job->tracking_token_created_at = now();
            }
        });
    }

    public function ensureTrackingToken(): string
    {
        if (empty($this->tracking_token)) {
            $this->tracking_token = \Illuminate\Support\Str::random(32);
            $this->tracking_token_created_at = now();
            $this->saveQuietly();
        }

        return $this->tracking_token;
    }

    public function trackingUrl(): string
    {
        return url('/track/' . $this->ensureTrackingToken());
    }

    protected function casts(): array
    {
        return [
            'tracking_token_created_at' => 'datetime',
            'delivery_date' => 'date',
            'estimated_delivery_date' => 'date',
            'supplier_delivery_date' => 'date',
            'received_date' => 'date',
            'needs_attention' => 'boolean',
            'attention_requested' => 'boolean',
            'attention_at' => 'datetime',
            'is_repeat_order' => 'boolean',
            'production_urgency_ids' => 'array',
            'shipment_method_ids' => 'array',
            'shipment_urgency_ids' => 'array',
            'allow_multiple_shipments' => 'boolean',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'commercial_value' => 'decimal:2',
        ];
    }

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function shippingSourceAddress(): BelongsTo { return $this->belongsTo(ClientShippingAddress::class, 'shipping_source_address_id'); }
    public function supplier(): BelongsTo { return $this->belongsTo(MasterRecord::class, 'supplier_id'); }
    public function sourceInquiry(): BelongsTo { return $this->belongsTo(Inquiry::class, 'source_inquiry_id'); }
    public function linkedInquiries(): BelongsToMany
    {
        return $this->belongsToMany(Inquiry::class, 'flow_job_inquiries', 'flow_job_id', 'inquiry_id')
            ->withPivot(['linked_by'])
            ->withTimestamps()
            ->orderByPivot('created_at')
            ->orderByPivot('id');
    }
    public function workflow(): BelongsTo { return $this->belongsTo(Workflow::class); }
    public function phase(): BelongsTo { return $this->belongsTo(WorkflowPhase::class, 'workflow_phase_id'); }
    public function startedFromPhase(): BelongsTo { return $this->belongsTo(WorkflowPhase::class, 'started_from_phase_id'); }
    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function coordinator(): BelongsTo { return $this->belongsTo(User::class, 'coordinator_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function attentionRequester(): BelongsTo { return $this->belongsTo(User::class, 'attention_by'); }
    public function cancelledBy(): BelongsTo { return $this->belongsTo(User::class, 'cancelled_by'); }
    public function orderFlag(): BelongsTo { return $this->belongsTo(MasterRecord::class, 'order_flag_id'); }
    public function tasks(): HasMany { return $this->hasMany(Task::class); }
    public function flaggedTasks(): HasMany { return $this->hasMany(Task::class)->whereNotNull('order_task_flag_id')->whereNull('completed_at')->orderBy('id'); }
    public function documents(): HasMany { return $this->hasMany(Document::class); }
    public function artworkTrackingPdf(): HasOne { return $this->hasOne(Document::class)->where('category', Document::CATEGORY_ARTWORK_TRACKING_PDF)->latestOfMany(); }
    public function items(): HasMany { return $this->hasMany(FlowJobItem::class, 'flow_job_id')->orderBy('sort_order'); }
    public function shipments(): HasMany { return $this->hasMany(OrderShipment::class, 'flow_job_id')->orderBy('sequence')->orderBy('id'); }
    public function holds(): HasMany { return $this->hasMany(OrderHold::class, 'flow_job_id')->latest('id'); }
    public function activeHold(): HasOne { return $this->hasOne(OrderHold::class, 'flow_job_id')->whereNull('ended_at'); }
    public function invoices(): HasMany { return $this->hasMany(Invoice::class, 'flow_job_id')->orderByDesc('issue_date')->orderByDesc('id'); }
    public function payments(): HasMany { return $this->hasMany(Payment::class, 'flow_job_id')->orderByDesc('payment_date')->orderByDesc('id'); }
    public function collection(): \Illuminate\Database\Eloquent\Relations\HasOne { return $this->hasOne(OrderCollection::class, 'flow_job_id'); }
    public function members(): HasMany { return $this->hasMany(FlowJobMember::class, 'flow_job_id'); }
    public function phaseHistories(): HasMany { return $this->hasMany(FlowJobPhaseHistory::class, 'flow_job_id'); }
    public function activities(): MorphMany { return $this->morphMany(Activity::class, 'subject'); }
    public function workflowEmailActivities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject')
            ->whereIn('event', [
                'job.artwork_emailed_to_order_team',
                'job.artwork_email_failed_to_order_team',
                'job.workflow_invoice_sent',
                'job.workflow_invoice_email_failed',
                'job.workflow_invoice_email_skipped',
                // When Order email is disabled the handoff can still be completed
                // manually. Keep that delivery state visible so the completed
                // task can resend later after email is enabled again.
                'job.workflow_email_skipped',
            ])
            ->latest('id');
    }
    public function workflowInvoiceActivities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject')
            ->where('event', 'job.workflow_invoice_prepared')
            ->latest('id');
    }
    public function latestProductionMonitorActivity(): MorphOne
    {
        return $this->morphOne(Activity::class, 'subject')
            ->ofMany(['id' => 'max'], fn ($query) => $query->where('activities.event', 'job.supplier_delivery_date_set'));
    }
    public function redoRecords(): HasMany { return $this->hasMany(OrderRedo::class, 'original_order_id')->orderBy('sequence'); }
    public function redoRecord(): \Illuminate\Database\Eloquent\Relations\HasOne { return $this->hasOne(OrderRedo::class, 'redo_order_id'); }
    public function createdActivity(): MorphOne { return $this->morphOne(Activity::class, 'subject')->oldestOfMany(); }
    public function latestActivity(): MorphOne { return $this->morphOne(Activity::class, 'subject')->latestOfMany(); }
    public function latestShipmentActivity(): MorphOne
    {
        return $this->morphOne(Activity::class, 'subject')
            ->ofMany(['id' => 'max'], fn ($query) => $query->where('activities.event', 'job.package_shipped'));
    }

    public function latestShipmentInformationActivity(): MorphOne
    {
        return $this->morphOne(Activity::class, 'subject')
            ->ofMany(['id' => 'max'], fn ($query) => $query->where('activities.event', 'job.shipment_information_confirmed'));
    }

    public function latestCourierLabelActivity(): MorphOne
    {
        return $this->morphOne(Activity::class, 'subject')
            ->ofMany(['id' => 'max'], fn ($query) => $query->where('activities.event', 'job.courier_label_generated'));
    }

    public function latestWorkflowInvoiceActivity(): MorphOne
    {
        return $this->morphOne(Activity::class, 'subject')
            ->ofMany(['id' => 'max'], fn ($query) => $query->where('activities.event', 'job.workflow_invoice_prepared'));
    }

    public function latestQcActivity(): MorphOne
    {
        return $this->morphOne(Activity::class, 'subject')
            ->ofMany(['id' => 'max'], fn ($query) => $query->whereIn('activities.event', ['job.qc_passed', 'job.qc_issue_reported']));
    }

    public function latestArtworkRevisionActivity(): MorphOne
    {
        return $this->morphOne(Activity::class, 'subject')
            ->ofMany(['id' => 'max'], fn ($query) => $query->where('activities.event', 'job.artwork_revision_requested'));
    }

    /**
     * The database keeps the legacy job_number column for backwards
     * compatibility, while the product UI now presents this entity as an
     * Order. Existing JOB-* identifiers are therefore displayed as ORDER-*
     * without breaking foreign keys, URLs, notifications, or imports.
     */
    public function displayOrderNumber(): string
    {
        $number = (string) $this->job_number;

        return str_starts_with($number, 'JOB-')
            ? 'ORDER-'.substr($number, 4)
            : $number;
    }

    /**
     * Resolve the client-facing reference number (e.g. NP-2026-0148),
     * stored in order_number or inherited from sourceInquiry.
     */
    public function getReferenceNumberAttribute(): ?string
    {
        if (!empty($this->attributes['order_number']) && $this->attributes['order_number'] !== ($this->attributes['job_number'] ?? null)) {
            return (string) $this->attributes['order_number'];
        }

        return $this->sourceInquiry?->reference_number
            ?? ($this->attributes['order_number'] ?? ($this->attributes['job_number'] ?? null));
    }

}
