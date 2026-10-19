<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderWorkflowSummary extends Model
{
    protected $fillable = [
        'flow_job_id',
        'workflow_id',
        'workflow_name',
        'workflow_phase_id',
        'current_phase_name',
        'current_phase_short_name',
        'current_phase_sequence',
        'stage_count',
        'current_task_count',
        'current_applicable_task_count',
        'current_completed_task_count',
        'next_task_id',
        'next_task_title',
        'next_task_assignee_id',
        'progress_percent',
        'order_status',
        'is_on_hold',
        'is_stale',
        'refreshed_at',
    ];

    protected function casts(): array
    {
        return [
            'current_phase_sequence' => 'integer',
            'stage_count' => 'integer',
            'current_task_count' => 'integer',
            'current_applicable_task_count' => 'integer',
            'current_completed_task_count' => 'integer',
            'progress_percent' => 'integer',
            'is_on_hold' => 'boolean',
            'is_stale' => 'boolean',
            'refreshed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(FlowJob::class, 'flow_job_id');
    }
}
