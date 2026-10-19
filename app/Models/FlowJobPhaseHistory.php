<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowJobPhaseHistory extends Model
{
    protected $table = 'flow_job_phase_histories';
    protected $fillable = [
        'flow_job_id',
        'workflow_phase_id',
        'changed_by',
        'phase_owner_id',
        'target_date',
        'health_override',
        'status',
        'entered_at',
        'completed_at',
    ];
    protected function casts(): array
    {
        return [
            'target_date' => 'date',
            'entered_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(FlowJob::class, 'flow_job_id');
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(WorkflowPhase::class, 'workflow_phase_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
