<?php

namespace App\Modules\OrderFlow\Data\Models;

use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class MasterRecord extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (MasterRecord $record): void {
            if (! $record->exists || $record->isDirty('name')) {
                $record->name = preg_replace('/\s+/u', ' ', trim((string) $record->name)) ?: '';
            }

            if (! $record->exists || $record->isDirty(['workspace_id', 'type', 'name'])) {
                $duplicate = self::query()
                    ->where('workspace_id', $record->workspace_id)
                    ->where('type', $record->type)
                    ->when($record->exists, fn (Builder $query) => $query->where($record->getKeyName(), '!=', $record->getKey()))
                    ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower((string) $record->name)])
                    ->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages([
                        'name' => 'This name already exists in this Master Data section. Duplicate entries are not allowed.',
                    ]);
                }
            }

            if ($record->type === MasterDataType::DocumentCategory->value
                && (! $record->exists || $record->isDirty(['workspace_id', 'type', 'sort_order']))) {
                $duplicateOrder = self::query()
                    ->where('workspace_id', $record->workspace_id)
                    ->where('type', MasterDataType::DocumentCategory->value)
                    ->where('sort_order', (int) $record->sort_order)
                    ->when($record->exists, fn (Builder $query) => $query->where($record->getKeyName(), '!=', $record->getKey()))
                    ->exists();

                if ($duplicateOrder) {
                    throw ValidationException::withMessages([
                        'sort_order' => 'This document order is already in use. Choose a different order.',
                    ]);
                }
            }
        });

        static::updating(function (MasterRecord $record): void {
            if ($record->isDirty('code')) {
                $record->code = $record->getOriginal('code');
            }
        });
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
