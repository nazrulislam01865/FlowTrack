<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'timezone',
        'default_currency',
        'logo_path',
        'favicon_path',
        'is_active',
        'company_profile',
        'supplier_portal_footer',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'company_profile' => 'array',
            'supplier_portal_footer' => 'array',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class);
    }
}
