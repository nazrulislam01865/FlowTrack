<?php

namespace App\Modules\OrderFlow\Data\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowJobPhaseHistory extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'entered_at' => 'datetime',
            'completed_at' => 'datetime',
            'ready_at' => 'datetime',
            'readiness_checked_at' => 'datetime',
            'readiness_details' => 'array',
        ];
    }

    public function job(): BelongsTo { return $this->belongsTo(FlowJob::class, 'flow_job_id'); }
    public function phase(): BelongsTo { return $this->belongsTo(WorkflowPhase::class, 'workflow_phase_id'); }
    public function changedBy(): BelongsTo { return $this->belongsTo(User::class, 'changed_by'); }
    public function changedByUser(): BelongsTo { return $this->belongsTo(User::class, 'changed_by'); }
}
