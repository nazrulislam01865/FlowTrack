<?php

namespace App\Modules\OrderFlow\Data\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowPhaseApproval extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(FlowJob::class, 'flow_job_id');
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(WorkflowPhase::class, 'workflow_phase_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
