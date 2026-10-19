<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskPack extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_active',
        'workspace_id',
        'code',
        'is_snapshot',
        'source_task_pack_id',
        'snapshot_job_id',
        'color',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_snapshot' => 'boolean'];
    }

    /** Legacy operational relation kept so existing Job logic remains compatible. */
    public function templates(): HasMany
    {
        return $this->hasMany(TaskPackTask::class)->orderBy('sequence');
    }

    /** SQL-structure relation used by Task Pack Setup. */
    public function items(): HasMany
    {
        return $this->hasMany(TaskPackItem::class)->orderBy('sort_order')->orderBy('id');
    }
}
