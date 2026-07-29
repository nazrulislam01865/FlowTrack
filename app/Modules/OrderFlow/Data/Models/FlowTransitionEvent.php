<?php

namespace App\Modules\OrderFlow\Data\Models;

use App\Models\User;
use LogicException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowTransitionEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Workflow transition events are immutable.'));
        static::deleting(fn () => throw new LogicException('Workflow transition events are immutable.'));
    }

    public function workspace(): BelongsTo { return $this->belongsTo(Workspace::class); }
    public function job(): BelongsTo { return $this->belongsTo(FlowJob::class, 'flow_job_id'); }
    public function task(): BelongsTo { return $this->belongsTo(FlowTask::class, 'flow_task_id'); }
    public function phase(): BelongsTo { return $this->belongsTo(WorkflowPhase::class, 'workflow_phase_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }
}
