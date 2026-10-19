<?php

namespace App\Support;

use App\Services\OrderWorkflowSetupService;

/**
 * Presents every Order through the authoritative seven-stage runtime contract.
 *
 * Older Orders can still point at historical workflow_phase rows such as
 * "Order Intake", "QC & Dispatch" or "Invoice & Payment". Those database
 * rows are retained for audit/history, but operational lists must never leak
 * retired stage labels back into the current UI.
 */
final class OrderStageResolver
{
    /**
     * @return array{name:string,short_name:string,sequence:int,color:string}
     */
    public static function resolve(
        ?string $phaseName = null,
        ?string $phaseShortName = null,
        ?int $phaseSequence = null,
        ?string $status = null,
        ?string $automationKey = null,
    ): array {
        $sequence = self::sequenceFromAutomationKey($automationKey)
            ?? self::sequenceFromName($phaseName, $status)
            ?? self::sequenceFromName($phaseShortName, $status)
            ?? self::validSequence($phaseSequence)
            ?? 1;

        $stage = OrderWorkflowSetupService::fixedStages()[$sequence - 1] ?? OrderWorkflowSetupService::fixedStages()[0];

        return [
            'name' => (string) $stage['name'],
            'short_name' => (string) ($stage['short'] ?? $stage['name']),
            'sequence' => $sequence,
            'color' => (string) ($stage['color'] ?? '#2d72d9'),
        ];
    }

    public static function sequenceFromAutomationKey(?string $automationKey): ?int
    {
        $key = strtoupper(trim((string) $automationKey));
        if ($key === '') return null;

        return match (true) {
            str_starts_with($key, 'NEW_') => 1,
            str_starts_with($key, 'ART_') => 2,
            str_starts_with($key, 'PROD_') => 3,
            str_starts_with($key, 'QC_') => 4,
            str_starts_with($key, 'SHIP_') => 5,
            str_starts_with($key, 'BILL_') => 6,
            str_starts_with($key, 'PAY_') => 7,
            default => null,
        };
    }

    public static function sequenceFromName(?string $phaseName, ?string $status = null): ?int
    {
        $name = self::normalize($phaseName);
        if ($name === '') return null;

        $status = self::normalize($status);

        if (self::containsAny($name, ['new order', 'order intake']) || $name === 'intake') return 1;
        if (self::containsAny($name, ['artwork', 'sample'])) return 2;
        if (str_contains($name, 'production')) return 3;

        // Historical five-stage workflows combined these operational stages.
        // Use the Order status only when it gives reliable evidence that the
        // second half of the old combined stage has already been reached.
        if (str_contains($name, 'invoice') && str_contains($name, 'payment')) {
            return self::containsAny($status, ['payment', 'paid', 'partially paid']) ? 7 : 6;
        }

        if (self::containsAny($name, ['billing', 'invoice'])) return 6;
        if (str_contains($name, 'payment')) return 7;

        if (str_contains($name, 'qc') && str_contains($name, 'dispatch')) {
            return self::containsAny($status, ['ship', 'dispatch', 'courier', 'tracking']) ? 5 : 4;
        }

        if (str_contains($name, 'receiving') && str_contains($name, 'dispatch')) {
            return self::containsAny($status, ['ship', 'dispatch', 'courier', 'tracking']) ? 5 : 4;
        }

        if (self::containsAny($name, ['shipment', 'dispatch', 'courier'])) return 5;
        if (self::containsAny($name, ['qc', 'quality', 'receiving'])) return 4;

        return null;
    }

    public static function matchesSequence(
        int $expectedSequence,
        ?string $phaseName = null,
        ?string $phaseShortName = null,
        ?int $phaseSequence = null,
        ?string $status = null,
        ?string $automationKey = null,
    ): bool {
        return self::resolve($phaseName, $phaseShortName, $phaseSequence, $status, $automationKey)['sequence'] === $expectedSequence;
    }

    private static function validSequence(?int $sequence): ?int
    {
        $sequence = (int) $sequence;
        return $sequence >= 1 && $sequence <= count(OrderWorkflowSetupService::fixedStages()) ? $sequence : null;
    }

    private static function normalize(?string $value): string
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/\s+/', ' ', $value) ?: $value;
        return $value;
    }

    /** @param list<string> $needles */
    private static function containsAny(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($value, $needle)) return true;
        }

        return false;
    }
}
