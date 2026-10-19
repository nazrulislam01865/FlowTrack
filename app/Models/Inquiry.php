<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Inquiry extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'inquiry_number',
        'client_id',
        'owner_id',
        'created_by',
        'source_task_pack_id',
        'reference_number',
        'client_contact',
        'received_date',
        'request_source',
        'subject',
        'requirement_notes',
        'target_price',
        'currency',
        'required_delivery_date',
        'priority',
        'initial_follow_up_date',
        'status',
        'result',
        'dead_reason',
        'dead_note',
        'converted_job_id',
        'completed_at',
        'source_workflow_template_id',
        'started_at',
        'needs_attention',
        'attention_reason',
        'attention_by',
        'attention_at',
    ];

    protected function casts(): array
    {
        return [
            'received_date' => 'date',
            'required_delivery_date' => 'date',
            'initial_follow_up_date' => 'date',
            'target_price' => 'decimal:4',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'needs_attention' => 'boolean',
            'attention_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function attentionRequester(): BelongsTo { return $this->belongsTo(User::class, 'attention_by'); }
    public function sourceTaskPack(): BelongsTo { return $this->belongsTo(TaskPack::class, 'source_task_pack_id'); }
    public function sourceWorkflow(): BelongsTo { return $this->belongsTo(WorkflowTemplate::class, 'source_workflow_template_id'); }
    public function convertedJob(): BelongsTo { return $this->belongsTo(FlowJob::class, 'converted_job_id'); }
    public function sourceOrder(): HasOne { return $this->hasOne(FlowJob::class, 'source_inquiry_id'); }
    public function linkedOrders(): BelongsToMany
    {
        return $this->belongsToMany(FlowJob::class, 'flow_job_inquiries', 'inquiry_id', 'flow_job_id')
            ->withPivot(['linked_by'])
            ->withTimestamps()
            ->orderByPivot('created_at');
    }
    public function items(): HasMany { return $this->hasMany(InquiryItem::class)->orderBy('sort_order'); }
    public function tasks(): HasMany { return $this->hasMany(InquiryTask::class)->orderBy('sequence'); }
    public function currentTask(): HasOne
    {
        return $this->hasOne(InquiryTask::class)
            ->whereNull('completed_at')
            ->orderByRaw('CASE WHEN inquiry_tasks.started_at IS NOT NULL THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN inquiry_tasks.started_at IS NOT NULL THEN inquiry_tasks.sequence END DESC')
            ->orderBy('inquiry_tasks.sequence');
    }
    public function documents(): HasMany { return $this->hasMany(InquiryDocument::class)->latest('id'); }
    public function rfqInvitations(): HasMany { return $this->hasMany(InquiryRfqInvitation::class)->latest('id'); }
    public function rfqSetting(): HasOne { return $this->hasOne(InquiryRfqSetting::class); }
    public function activities(): MorphMany { return $this->morphMany(Activity::class, 'subject'); }
}
