<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskPackItem extends Model
{
    protected $fillable = [
        'id',
        'task_pack_id',
        'title',
        'description',
        'default_assignee_id',
        'default_assignee_type',
        'default_department_id',
        'priority_id',
        'document_category_id',
        'due_offset_days',
        'is_required',
        'sort_order',
        'source_task_pack_item_id',
        'standard_duration_value',
        'standard_duration_unit',
        'timer_start_rule',
        'timer_stop_rule',
        'pause_statuses',
        'work_calendar',
        'set_due_from_standard_duration',
        'allow_efficiency_override',
        'document_required_before_completion',
        'allow_multiple_documents',
        'document_instructions',
        'automation_key',
        'color',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'due_offset_days' => 'integer',
            'document_required_before_completion' => 'boolean',
            'allow_multiple_documents' => 'boolean',
            'standard_duration_value' => 'float',
            'set_due_from_standard_duration' => 'boolean',
            'allow_efficiency_override' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function taskPack(): BelongsTo { return $this->belongsTo(TaskPack::class); }
    public function sourceItem(): BelongsTo { return $this->belongsTo(self::class, 'source_task_pack_item_id'); }
    public function defaultAssignee(): BelongsTo { return $this->belongsTo(User::class, 'default_assignee_id'); }
    public function defaultDepartment(): BelongsTo { return $this->belongsTo(MasterRecord::class, 'default_department_id'); }
    public function priority(): BelongsTo { return $this->belongsTo(MasterRecord::class, 'priority_id'); }
    public function documentCategory(): BelongsTo { return $this->belongsTo(MasterRecord::class, 'document_category_id'); }
}
