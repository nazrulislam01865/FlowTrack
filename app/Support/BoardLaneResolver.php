<?php

namespace App\Support;

final class BoardLaneResolver
{
    public const DEFAULT_NOT_STARTED_STATUS = 'Not Started';

    /**
     * Build the task-board lane labels from active Task Status master data.
     *
     * The configured sort order is preserved, except that a configured
     * "Not Start"/"Not Started" lane is always moved to the first position.
     * When master data has no such status, the canonical "Not Started" lane is
     * prepended so newly generated and not-yet-started tasks always have a lane.
     *
     * @param iterable<mixed> $configuredStatuses
     * @return list<string>
     */
    public static function taskStatuses(iterable $configuredStatuses): array
    {
        $statuses = [];
        $seen = [];

        foreach ($configuredStatuses as $status) {
            $label = trim((string) $status);
            if ($label === '') {
                continue;
            }

            $key = self::normalise($label);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $statuses[] = $label;
        }

        $notStarted = null;
        $remaining = [];

        foreach ($statuses as $status) {
            if (self::isNotStarted($status)) {
                $notStarted ??= $status;
                continue;
            }

            $remaining[] = $status;
        }

        return array_values(array_merge([
            $notStarted ?? self::DEFAULT_NOT_STARTED_STATUS,
        ], $remaining));
    }

    public static function isNotStarted(?string $status): bool
    {
        return in_array(self::normalise((string) $status), ['not start', 'not started'], true);
    }

    public static function isCompleted(?string $status): bool
    {
        return self::normalise((string) $status) === 'completed';
    }

    public static function taskStatusMatches(?string $actualStatus, ?string $laneStatus): bool
    {
        if (self::isNotStarted($actualStatus) && self::isNotStarted($laneStatus)) {
            return true;
        }

        return self::normalise((string) $actualStatus) === self::normalise((string) $laneStatus);
    }

    /** @return list<string> */
    public static function databaseStatusValues(string $status): array
    {
        if (self::isNotStarted($status)) {
            return array_values(array_unique([$status, 'Not Started', 'Not Start']));
        }

        return [$status];
    }

    private static function normalise(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[_-]+/', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }
}
