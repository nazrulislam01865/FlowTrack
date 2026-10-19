<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowTaskChecklistItem extends Model
{
    protected $table = 'flow_task_checklist_items';
    protected $fillable = [
        'flow_task_id',
        'label',
        'is_completed',
        'sort_order',
    ];
    protected function casts(): array
    {
        return ['is_completed' => 'boolean'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'flow_task_id');
    }
}
