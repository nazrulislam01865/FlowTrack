<?php

namespace App\Modules\OrderFlow\Data\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Role extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (Role $role): void {
            if (blank($role->code)) {
                $role->code = Str::of($role->slug ?: $role->name)
                    ->ascii()
                    ->upper()
                    ->replaceMatches('/[^A-Z0-9]+/', '_')
                    ->trim('_')
                    ->substr(0, 80)
                    ->value();
            }
            $role->default_scope = $role->default_scope ?: 'assigned_jobs';
            $role->is_active = $role->is_active ?? true;
            $role->sensitive_fields = $role->sensitive_fields ?: [];
        });
    }

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'sensitive_fields' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class);
    }

    public function moduleAccess(): HasMany
    {
        return $this->hasMany(RoleModuleAccess::class);
    }
}
