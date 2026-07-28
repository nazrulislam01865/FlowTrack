<?php

namespace App\Modules\OrderFlow\Data\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowPhase extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'allow_job_start' => 'boolean',
            'is_skippable' => 'boolean',
            'requires_approval' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplate::class, 'workflow_template_id');
    }

    public function taskPack(): BelongsTo
    {
        return $this->belongsTo(TaskPack::class);
    }

    public function documentCategory(): BelongsTo
    {
        return $this->belongsTo(MasterRecord::class, 'document_category_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(FlowTask::class);
    }

    public function jobHistories(): HasMany
    {
        return $this->hasMany(FlowJobPhaseHistory::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(FlowPhaseApproval::class);
    }
}
