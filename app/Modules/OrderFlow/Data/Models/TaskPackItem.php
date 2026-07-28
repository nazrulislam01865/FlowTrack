<?php

namespace App\Modules\OrderFlow\Data\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskPackItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_required' => 'boolean'];
    }

    public function pack(): BelongsTo
    {
        return $this->belongsTo(TaskPack::class, 'task_pack_id');
    }

    public function defaultAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_assignee_id');
    }

    public function defaultDepartment(): BelongsTo
    {
        return $this->belongsTo(MasterRecord::class, 'default_department_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(FlowTask::class);
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(MasterRecord::class, 'priority_id');
    }
}
