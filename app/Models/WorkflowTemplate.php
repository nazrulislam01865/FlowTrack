<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowTemplate extends Model
{
    protected $fillable = [
        'id',
        'workspace_id',
        'code',
        'name',
        'description',
        'is_active',
        'is_default',
        'version',
        'applies_to',
        'client_availability',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function phases(): HasMany
    {
        return $this->hasMany(WorkflowPhase::class, 'workflow_template_id')->orderBy('sequence');
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'workflow_template_client')
            ->withTimestamps();
    }

    public function scopeAvailableFor(Builder $query, string $appliesTo, ?int $clientId = null): Builder
    {
        return $query
            ->where('applies_to', $appliesTo)
            ->where(function (Builder $availability) use ($clientId): void {
                $availability->where('client_availability', 'all');

                if ($clientId) {
                    $availability->orWhere(function (Builder $specific) use ($clientId): void {
                        $specific->where('client_availability', 'specific')
                            ->whereHas('clients', fn (Builder $clients) => $clients->whereKey($clientId));
                    });
                }
            });
    }

    /**
     * Workflows that may be chosen while creating an Order.
     *
     * Normal Order workflows remain available according to their configured
     * client availability. A client-specific Inquiry workflow is also exposed
     * for that exact client so an Inquiry-led client (for example NEP) can
     * carry its configured workflow into Create Order while still allowing the
     * user to manually switch back to another Order workflow. Generic Inquiry
     * workflows are deliberately not exposed to unrelated clients.
     */
    public function scopeAvailableForOrderCreation(Builder $query, ?int $clientId = null): Builder
    {
        return $query->where(function (Builder $available) use ($clientId): void {
            $available->where(function (Builder $orders) use ($clientId): void {
                $orders->where('applies_to', 'orders')
                    ->where(function (Builder $availability) use ($clientId): void {
                        $availability->where('client_availability', 'all');

                        if ($clientId) {
                            $availability->orWhere(function (Builder $specific) use ($clientId): void {
                                $specific->where('client_availability', 'specific')
                                    ->whereHas('clients', fn (Builder $clients) => $clients->whereKey($clientId));
                            });
                        }
                    });
            });

            if ($clientId) {
                $available->orWhere(function (Builder $inquirySpecific) use ($clientId): void {
                    $inquirySpecific->where('applies_to', 'inquiries')
                        ->where('client_availability', 'specific')
                        ->whereHas('clients', fn (Builder $clients) => $clients->whereKey($clientId));
                });
            }
        });
    }
}
