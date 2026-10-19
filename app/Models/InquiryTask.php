<?php

namespace App\Models;

use App\Models\Concerns\TracksTaskAssigneePerformance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InquiryTask extends Model
{
    use SoftDeletes, TracksTaskAssigneePerformance;

    public const MAX_DOCUMENTS = 10;

    protected $fillable = [
        'inquiry_id',
        'source_task_pack_item_id',
        'assignee_id',
        'title',
        'description',
        'sequence',
        'due_date',
        'status',
        'requires_submission',
        'submission_label',
        'completed_at',
        'started_at',
        'setup_assignee_id',
        'inquiry_task_status_id',
        'needs_attention',
        'attention_reason',
        'source_workflow_phase_id',
        'assignee_assigned_at',
        'assignee_at_completion',
        'assignee_assigned_at_completion',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'requires_submission' => 'boolean',
            'needs_attention' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'assignee_assigned_at' => 'datetime',
            'assignee_assigned_at_completion' => 'datetime',
        ];
    }

    public function inquiry(): BelongsTo { return $this->belongsTo(Inquiry::class); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assignee_id'); }
    public function completionAssignee(): BelongsTo { return $this->belongsTo(User::class, 'assignee_at_completion'); }
    public function setupAssignee(): BelongsTo { return $this->belongsTo(User::class, 'setup_assignee_id'); }
    public function sourceTaskPackItem(): BelongsTo { return $this->belongsTo(TaskPackItem::class, 'source_task_pack_item_id'); }
    public function sourceWorkflowPhase(): BelongsTo { return $this->belongsTo(WorkflowPhase::class, 'source_workflow_phase_id'); }
    public function taskStatus(): BelongsTo { return $this->belongsTo(MasterRecord::class, 'inquiry_task_status_id'); }
    public function documents(): HasMany { return $this->hasMany(InquiryDocument::class)->latest('id'); }
    public function links(): HasMany { return $this->hasMany(InquiryTaskLink::class)->latest('id'); }
    public function comments(): HasMany { return $this->hasMany(InquiryTaskComment::class)->latest('id'); }
}
