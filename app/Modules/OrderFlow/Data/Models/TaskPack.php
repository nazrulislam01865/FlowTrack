<?php

namespace App\Modules\OrderFlow\Data\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskPack extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(function (TaskPack $taskPack): void {
            if ($taskPack->isDirty('code')) {
                $taskPack->code = $taskPack->getOriginal('code');
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TaskPackItem::class)->orderBy('sort_order');
    }
}
