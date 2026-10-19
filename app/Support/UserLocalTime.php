<?php

namespace App\Support;

use App\Services\WorkspaceSettingsService;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;

final class UserLocalTime
{
    public static function timezone(): string
    {
        return app(WorkspaceSettingsService::class)->displayTimezone();
    }

    public static function localize(CarbonInterface|DateTimeInterface|null $value): ?CarbonInterface
    {
        if ($value === null) return null;

        $carbon = $value instanceof CarbonInterface
            ? $value->copy()
            : Carbon::instance($value);

        return $carbon->setTimezone(self::timezone());
    }

    public static function format(CarbonInterface|DateTimeInterface|null $value, string $format, string $fallback = '—'): string
    {
        return self::localize($value)?->format($format) ?? $fallback;
    }

    public static function isToday(CarbonInterface|DateTimeInterface|null $value): bool
    {
        return self::localize($value)?->toDateString() === app(WorkspaceSettingsService::class)->localToday()->toDateString();
    }

    public static function isDatePast(?CarbonInterface $value): bool
    {
        return $value !== null
            && $value->toDateString() < app(WorkspaceSettingsService::class)->localToday()->toDateString();
    }
}
