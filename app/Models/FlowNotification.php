<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class FlowNotification extends Model
{
    protected $fillable = [
        'user_id',
        'flow_job_id',
        'type',
        'title',
        'message',
        'read_at',
        'flow_task_id',
        'inquiry_id',
        'inquiry_task_id',
        'actor_id',
    ];

    protected $table = 'flow_notifications';

    private static ?bool $actorColumnAvailable = null;

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        // Deployments can briefly run new PHP code before the database migration
        // has completed. Strip the new attribute during that window instead of
        // turning every notification write into an SQL "unknown column" error.
        static::saving(function (FlowNotification $notification): void {
            if (! static::supportsActorIdentity()) {
                unset($notification->attributes['actor_id']);
            }
        });
    }

    public static function supportsActorIdentity(): bool
    {
        // Cache the stable deployed state, but never cache a missing column. That
        // allows a long-running local server/queue process to recover immediately
        // after `php artisan migrate` adds actor_id.
        if (static::$actorColumnAvailable === true) {
            return true;
        }

        $available = Schema::hasTable('flow_notifications')
            && Schema::hasColumn('flow_notifications', 'actor_id');

        if ($available) {
            static::$actorColumnAvailable = true;
        }

        return $available;
    }

    public static function forgetActorSchemaCache(): void
    {
        static::$actorColumnAvailable = null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(FlowJob::class, 'flow_job_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'flow_task_id');
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function inquiryTask(): BelongsTo
    {
        return $this->belongsTo(InquiryTask::class);
    }
}
