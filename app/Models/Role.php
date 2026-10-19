<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'is_system',
        'workspace_id',
        'code',
        'description',
        'default_scope',
        'is_active',
        'sensitive_fields',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'sensitive_fields' => 'array',
        ];
    }

    public function permissions(): BelongsToMany { return $this->belongsToMany(Permission::class); }

    /** Users assigned this role through the multi-role pivot. */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles', 'role_id', 'user_id')->withTimestamps();
    }

    /** Legacy primary-role relationship retained for compatibility/auditing. */
    public function primaryUsers(): HasMany { return $this->hasMany(User::class, 'role_id'); }

    public function moduleAccess(): HasMany { return $this->hasMany(RoleModuleAccess::class); }
    public function memberships(): HasMany { return $this->hasMany(WorkspaceMembership::class); }
}
