<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Saved recipients of the "Send Artwork to Order Team" handoff. */
class ArtworkHandoffContact extends Model
{
    public const TYPES = [
        'client' => 'Client',
        'supplier' => 'Supplier',
        'manager' => 'Manager',
        'internal_team' => 'Internal team',
        'other' => 'Other',
    ];

    protected $fillable = [
        'workspace_id', 'email', 'name', 'phone', 'contact_type', 'address', 'created_by',
    ];

    public function scopeInWorkspace(Builder $query, int $workspaceId): Builder
    {
        return $query->where('workspace_id', $workspaceId);
    }
}
