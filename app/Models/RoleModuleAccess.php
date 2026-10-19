<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoleModuleAccess extends Model
{
    protected $table = 'role_module_access';
    protected $fillable = [
        'role_id',
        'module_code',
        'record_scope',
        'actions',
    ];

    protected function casts(): array
    {
        return ['actions' => 'array'];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
