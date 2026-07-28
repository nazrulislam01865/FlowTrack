<?php

namespace App\Modules\OrderFlow\Data\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }
}
