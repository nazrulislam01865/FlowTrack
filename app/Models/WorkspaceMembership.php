<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceMembership extends Model
{
    protected $fillable = [
        'workspace_id',
        'user_id',
        'role_id',
        'department_id',
        'job_title',
        'status',
        'joined_at',
        'business_unit',
    ];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function role(): BelongsTo { return $this->belongsTo(Role::class); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function workspace(): BelongsTo { return $this->belongsTo(Workspace::class); }
}
