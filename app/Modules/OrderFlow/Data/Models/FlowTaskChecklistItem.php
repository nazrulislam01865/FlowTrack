<?php

namespace App\Modules\OrderFlow\Data\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowTaskChecklistItem extends Model
{
    protected $table = 'flow_task_checklist_items';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_completed' => 'boolean'];
    }

    public function task(): BelongsTo { return $this->belongsTo(FlowTask::class, 'flow_task_id'); }
}
