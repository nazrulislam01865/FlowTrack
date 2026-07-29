<?php

namespace App\Modules\OrderFlow\Data\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use SoftDeletes;

    protected static function booted(): void
    {
        static::updating(function (Client $client): void {
            if ($client->isDirty('code')) {
                $client->code = $client->getOriginal('code');
            }
        });
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return ['outstanding_balance' => 'decimal:2'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function accountManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_manager_id');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(FlowJob::class);
    }
}
