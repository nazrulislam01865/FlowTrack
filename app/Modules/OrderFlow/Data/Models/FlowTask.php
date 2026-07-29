<?php

namespace App\Modules\OrderFlow\Data\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FlowTask extends Model
{
    use SoftDeletes;

    protected $table = 'flow_tasks';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'attention_required' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo { return $this->belongsTo(FlowJob::class, 'flow_job_id'); }
    public function phase(): BelongsTo { return $this->belongsTo(WorkflowPhase::class, 'workflow_phase_id'); }
    public function packItem(): BelongsTo { return $this->belongsTo(TaskPackItem::class, 'task_pack_item_id'); }
    public function parent(): BelongsTo { return $this->belongsTo(self::class, 'parent_id'); }
    public function children(): HasMany { return $this->hasMany(self::class, 'parent_id'); }
    public function dependency(): BelongsTo { return $this->belongsTo(self::class, 'dependency_id'); }
    public function dependents(): HasMany { return $this->hasMany(self::class, 'dependency_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assignee_id'); }
    public function status(): BelongsTo { return $this->belongsTo(MasterRecord::class, 'task_status_id'); }
    public function priority(): BelongsTo { return $this->belongsTo(MasterRecord::class, 'priority_id'); }
    public function checklistItems(): HasMany { return $this->hasMany(FlowTaskChecklistItem::class); }
    public function comments(): HasMany { return $this->hasMany(FlowTaskComment::class)->latest(); }
    public function transitionEvents(): HasMany { return $this->hasMany(FlowTransitionEvent::class, 'flow_task_id')->latest('occurred_at'); }
}
