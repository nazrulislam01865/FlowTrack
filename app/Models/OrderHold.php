<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderHold extends Model
{
    public const FROM_CLIENT = 'client';
    public const FROM_INTERNAL = 'internal';
    public const FROM_MANAGEMENT = 'management';

    public const HOLD_FROM_OPTIONS = [
        self::FROM_CLIENT => 'Client',
        self::FROM_INTERNAL => 'Internal',
        self::FROM_MANAGEMENT => 'Management',
    ];

    protected $fillable = [
        'flow_job_id',
        'hold_from',
        'source_name',
        'reason',
        'held_by',
        'started_at',
        'ended_at',
        'released_by',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(FlowJob::class, 'flow_job_id');
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'held_by');
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }

    public function holdFromLabel(): string
    {
        return self::labelFor($this->hold_from);
    }

    public static function labelFor(?string $value): string
    {
        return self::HOLD_FROM_OPTIONS[strtolower(trim((string) $value))] ?? '—';
    }

    public static function durationLabel(?CarbonInterface $startedAt, ?CarbonInterface $endedAt): string
    {
        if (! $startedAt || ! $endedAt) {
            return '—';
        }

        $seconds = max(0, (int) floor($startedAt->diffInSeconds($endedAt)));
        $days = intdiv($seconds, 86400);
        $seconds %= 86400;
        $hours = intdiv($seconds, 3600);
        $seconds %= 3600;
        $minutes = intdiv($seconds, 60);

        $parts = [];
        if ($days > 0) {
            $parts[] = $days.' '.($days === 1 ? 'day' : 'days');
        }
        if ($hours > 0) {
            $parts[] = $hours.' '.($hours === 1 ? 'hour' : 'hours');
        }
        if ($minutes > 0 || $parts === []) {
            $parts[] = $minutes.' '.($minutes === 1 ? 'minute' : 'minutes');
        }

        return implode(', ', $parts);
    }
}
