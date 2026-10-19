<?php

namespace App\Services;

use App\Models\MasterRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class WorkspaceSettingsService
{
    private const TIMEZONE_CACHE_PREFIX = 'flowtrack:workspace:timezone:';

    public function timezone(): string
    {
        $workspaceId = app(SetupContext::class)->workspaceId();

        return Cache::remember(self::TIMEZONE_CACHE_PREFIX.$workspaceId, now()->addMinutes(10), function () use ($workspaceId) {
            $stored = MasterRecord::query()
                ->where('workspace_id', $workspaceId)
                ->where('type', 'system_setting')
                ->where('code', 'TIMEZONE')
                ->value('description');

            $timezone = trim((string) $stored);

            return in_array($timezone, DateTimeZone::listIdentifiers(), true)
                ? $timezone
                : (string) config('app.timezone', 'UTC');
        });
    }

    public function displayTimezone(): string
    {
        $sessionTimezone = request()->hasSession()
            ? trim((string) request()->session()->get('flowtrack_timezone', ''))
            : '';

        if ($sessionTimezone !== '' && in_array($sessionTimezone, DateTimeZone::listIdentifiers(), true)) {
            return $sessionTimezone;
        }

        return $this->timezone();
    }

    public function localNow(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->setTimezone($this->displayTimezone());
    }

    public function localToday(): CarbonImmutable
    {
        return $this->localNow()->startOfDay();
    }

    public function localWeekUtcBounds(): array
    {
        $now = $this->localNow();

        return [
            $now->startOfWeek()->utc(),
            $now->endOfWeek()->utc(),
        ];
    }

    /**
     * Convert optional local calendar dates to inclusive UTC bounds.
     * This keeps list date filtering aligned with the workspace/user display
     * timezone instead of treating YYYY-MM-DD values as UTC dates.
     */
    public function localDateRangeUtcBounds(?string $from, ?string $to): array
    {
        $fromDate = $this->parseLocalDate($from);
        $toDate = $this->parseLocalDate($to);

        if ($fromDate && $toDate && $fromDate->gt($toDate)) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        return [
            $fromDate?->startOfDay()->utc(),
            $toDate?->endOfDay()->utc(),
        ];
    }

    private function parseLocalDate(?string $value): ?CarbonImmutable
    {
        $value = trim((string) $value);
        if ($value === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $this->displayTimezone());
        } catch (\Throwable) {
            return null;
        }

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    public function timezoneOptions(): array
    {
        return DateTimeZone::listIdentifiers();
    }

    public function setTimezone(string $timezone, User $actor): string
    {
        abort_unless(app(AccessControlService::class)->isAdministrator($actor), 403);

        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw ValidationException::withMessages([
                'workspaceTimezone' => 'Select a valid time zone.',
            ]);
        }

        $workspaceId = app(SetupContext::class)->workspaceId();
        $record = MasterRecord::withTrashed()->firstOrNew([
            'workspace_id' => $workspaceId,
            'type' => 'system_setting',
            'code' => 'TIMEZONE',
        ]);
        if ($record->trashed()) $record->restore();
        $record->fill([
            'name' => 'Workspace time zone',
            'description' => $timezone,
            'status' => 'active',
            'sort_order' => 0,
            'metadata' => ['timezone' => $timezone],
        ])->save();

        Cache::forget(self::TIMEZONE_CACHE_PREFIX.$workspaceId);

        return $timezone;
    }
}
