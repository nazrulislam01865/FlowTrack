<?php

namespace App\Modules\OrderFlow\Data\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoleModuleAccess extends Model
{
    protected $table = 'role_module_access';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'actions' => 'array',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
