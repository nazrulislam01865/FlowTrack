<?php

namespace App\Models;

use App\Models\Concerns\TracksTaskAssigneePerformance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Task extends Model
{
    use SoftDeletes, TracksTaskAssigneePerformance;

    protected $fillable = [
        'task_number',
        'flow_job_id',
        'workflow_phase_id',
        'task_pack_task_id',
        'assignee_id',
        'title',
        'status',
        'priority',
        'progress',
        'due_date',
        'needs_attention',
        'attention_reason',
        'completed_at',
        'setup_assignee_id',
        'document_category_id',
        'document_requirement_source',
        'description',
        'start_date',
        'task_flag_id',
        'order_task_status_id',
        'order_task_flag_id',
        'assignee_assigned_at',
        'assignee_at_completion',
        'assignee_assigned_at_completion',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'due_date' => 'date',
            'needs_attention' => 'boolean',
            'completed_at' => 'datetime',
            'assignee_assigned_at' => 'datetime',
            'assignee_assigned_at_completion' => 'datetime',
        ];
    }

    public function supplierAssignment(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(SupplierTaskAssignment::class, 'task_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(FlowJob::class, 'flow_job_id');
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(WorkflowPhase::class, 'workflow_phase_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function completionAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_at_completion');
    }

    public function attentionFlag(): BelongsTo
    {
        return $this->belongsTo(MasterRecord::class, 'task_flag_id');
    }

    public function orderTaskStatus(): BelongsTo
    {
        return $this->belongsTo(MasterRecord::class, 'order_task_status_id');
    }

    public function orderTaskFlag(): BelongsTo
    {
        return $this->belongsTo(MasterRecord::class, 'order_task_flag_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(TaskPackTask::class, 'task_pack_task_id');
    }

    public function setupTemplate(): BelongsTo
    {
        return $this->belongsTo(TaskPackItem::class, 'task_pack_task_id');
    }

    public function documentCategory(): BelongsTo
    {
        return $this->belongsTo(MasterRecord::class, 'document_category_id');
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(FlowTaskChecklistItem::class, 'flow_task_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(FlowTaskComment::class, 'flow_task_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'task_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(TaskLink::class, 'task_id')->latest('id');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }
}
