<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Presentation-only mapping for the Create Order shipping-method prototype.
 *
 * Shipment methods remain Master Data records. Standard Express uses the
 * existing Shipment Urgency records for its Normal/Urgent/Super Urgent level,
 * while Sea/Air remain ordinary shipment methods. This keeps the UI vocabulary
 * separate from persistence and avoids duplicating shipping labels in Blade.
 */
final class CreateOrderShippingMethodPresenter
{
    public static function methodKind(mixed $method): string
    {
        $code = strtoupper(trim((string) data_get($method, 'code', '')));
        $name = strtolower(trim((string) data_get($method, 'name', '')));

        if ($code === 'SEA' || str_contains($name, 'sea')) return 'sea';
        if ($code === 'AIR' || str_contains($name, 'air')) return 'air';
        if ($code === 'EXP' || str_contains($name, 'express') || str_contains($name, 'courier')) return 'express';
        if ($code === 'ROAD' || $code === 'TRUCK' || str_contains($name, 'road') || str_contains($name, 'truck')) return 'road';

        return 'other';
    }

    public static function methodLabel(mixed $method): string
    {
        return match (self::methodKind($method)) {
            'sea' => 'Sea Shipping',
            'air' => 'Air Shipping',
            'express' => 'Standard Express Shipping',
            'road' => 'Road Freight',
            default => trim((string) data_get($method, 'name', '')) ?: 'Shipping method',
        };
    }

    public static function methodEstimate(mixed $method): string
    {
        return match (self::methodKind($method)) {
            'sea' => 'About 1 month',
            'air' => 'About 10–15 days',
            default => '',
        };
    }

    public static function urgencyKind(mixed $urgency): string
    {
        $name = strtolower(trim((string) data_get($urgency, 'name', '')));

        if (str_contains($name, 'super')) return 'super-urgent';
        if (str_contains($name, 'urgent')) return 'urgent';
        if (str_contains($name, 'normal')) return 'normal';

        return 'other';
    }

    public static function urgencyLabel(mixed $urgency): string
    {
        return match (self::urgencyKind($urgency)) {
            'normal' => 'Normal',
            'urgent' => 'Urgent',
            'super-urgent' => 'Super Urgent',
            default => trim((string) data_get($urgency, 'name', '')) ?: 'Express',
        };
    }

    public static function urgencyEstimate(mixed $urgency): string
    {
        return match (self::urgencyKind($urgency)) {
            'normal' => 'About 7 days',
            'urgent' => 'About 3 days',
            'super-urgent' => 'About 1–2 days',
            default => '',
        };
    }

    public static function directMethods(Collection $methods): Collection
    {
        return $methods
            ->filter(fn ($method) => self::methodKind($method) !== 'express')
            ->sortBy(fn ($method) => match (self::methodKind($method)) {
                'sea' => 10,
                'air' => 20,
                'road' => 30,
                default => 40 + (int) data_get($method, 'sort_order', 0),
            })
            ->values();
    }

    public static function expressMethod(Collection $methods): mixed
    {
        return $methods->first(fn ($method) => self::methodKind($method) === 'express');
    }

    /**
     * Normal is intentionally virtual: an empty Shipment Urgency array remains
     * the established persisted meaning of normal service and keeps reporting
     * semantics backward compatible.
     */
    public static function expressUrgencies(Collection $urgencies): Collection
    {
        $canonical = collect([
            ['id' => null, 'name' => 'Normal', 'kind' => 'normal', 'estimate' => 'About 7 days'],
        ]);

        $real = $urgencies
            ->reject(fn ($urgency) => self::urgencyKind($urgency) === 'normal')
            ->sortBy(fn ($urgency) => match (self::urgencyKind($urgency)) {
                'urgent' => 10,
                'super-urgent' => 20,
                default => 30 + (int) data_get($urgency, 'sort_order', 0),
            })
            ->map(fn ($urgency) => [
                'id' => (int) data_get($urgency, 'id'),
                'name' => self::urgencyLabel($urgency),
                'kind' => self::urgencyKind($urgency),
                'estimate' => self::urgencyEstimate($urgency),
            ]);

        return $canonical->concat($real)->values();
    }


    /**
     * Build the single Planning & ownership selector used by Order Details.
     *
     * Direct methods (Sea/Air/Road/other) and Express urgency levels are one
     * business choice, even though they are persisted in two legacy fields.
     * A composite value prevents Master Data IDs from colliding across types.
     *
     * @return Collection<int,array{value:string,name:string,kind:string,tone:string,method_id:int,urgency_id:?int}>
     */
    public static function orderShippingOptions(Collection $methods, Collection $urgencies): Collection
    {
        $direct = self::directMethods($methods)->map(function ($method): array {
            $methodId = (int) data_get($method, 'id');
            $kind = self::methodKind($method);

            return [
                'value' => self::selectionValue($methodId, null),
                'name' => self::methodLabel($method),
                'kind' => $kind,
                'tone' => 'normal',
                'method_id' => $methodId,
                'urgency_id' => null,
            ];
        });

        $expressMethod = self::expressMethod($methods);
        if (! $expressMethod) {
            return $direct->values();
        }

        $expressMethodId = (int) data_get($expressMethod, 'id');
        $express = self::expressUrgencies($urgencies)->map(function (array $urgency) use ($expressMethodId): array {
            $urgencyId = filled($urgency['id'] ?? null) ? (int) $urgency['id'] : null;
            $kind = (string) ($urgency['kind'] ?? 'normal');

            return [
                'value' => self::selectionValue($expressMethodId, $urgencyId),
                'name' => $urgencyId ? (string) $urgency['name'] : 'Normal Service',
                'kind' => 'express',
                'tone' => in_array($kind, ['urgent', 'super-urgent'], true) ? $kind : 'normal',
                'method_id' => $expressMethodId,
                'urgency_id' => $urgencyId,
            ];
        });

        return $direct->concat($express)->values();
    }

    /** @return array{value:string,name:string,tone:string,method_id:?int,urgency_id:?int} */
    public static function orderShippingState(
        Collection $methods,
        Collection $urgencies,
        array $selectedMethodIds,
        array $selectedUrgencyIds,
    ): array {
        $methodId = collect($selectedMethodIds)
            ->map(fn ($id) => (int) $id)
            ->first(fn (int $id) => $id > 0);
        $urgencyId = collect($selectedUrgencyIds)
            ->map(fn ($id) => (int) $id)
            ->first(fn (int $id) => $id > 0);

        // Backward compatibility for Orders created before shipment_method_ids.
        if (! $methodId && $urgencyId) {
            $methodId = (int) data_get(self::expressMethod($methods), 'id', 0) ?: null;
        }

        $options = self::orderShippingOptions($methods, $urgencies);
        if ($methodId) {
            $value = self::selectionValue($methodId, $urgencyId);
            $selected = $options->first(fn (array $option): bool => $option['value'] === $value);
            if ($selected) {
                return [
                    'value' => $selected['value'],
                    'name' => $selected['name'],
                    'tone' => $selected['tone'],
                    'method_id' => $selected['method_id'],
                    'urgency_id' => $selected['urgency_id'],
                ];
            }
        }

        // Preserve the established display for an Order that has no explicit
        // shipping selection yet. If Express exists, point the editor at its
        // virtual Normal option so the first save is canonical.
        $normal = $options->first(fn (array $option): bool => $option['kind'] === 'express' && $option['urgency_id'] === null);

        return [
            'value' => (string) ($normal['value'] ?? ''),
            'name' => (string) ($normal['name'] ?? 'Normal Service'),
            'tone' => 'normal',
            'method_id' => isset($normal['method_id']) ? (int) $normal['method_id'] : null,
            'urgency_id' => null,
        ];
    }

    public static function selectionValue(int $methodId, ?int $urgencyId): string
    {
        return 'm:'.$methodId.':u:'.($urgencyId ?: 0);
    }

    /** @return array{method_id:int,urgency_id:?int} */
    public static function parseSelectionValue(string $value): array
    {
        if (! preg_match('/^m:(\\d+):u:(\\d+)$/', trim($value), $matches)) {
            return ['method_id' => 0, 'urgency_id' => null];
        }

        $methodId = (int) $matches[1];
        $urgencyId = (int) $matches[2];

        return [
            'method_id' => $methodId,
            'urgency_id' => $urgencyId > 0 ? $urgencyId : null,
        ];
    }

    public static function selectedCard(
        Collection $methods,
        Collection $urgencies,
        array $selectedMethodIds,
        array $selectedUrgencyIds,
    ): ?array {
        $selectedId = collect($selectedMethodIds)
            ->map(fn ($id) => (int) $id)
            ->first(fn (int $id) => $id > 0);

        if (!$selectedId) return null;

        $method = $methods->first(fn ($option) => (int) data_get($option, 'id') === $selectedId);
        if (!$method) return null;

        $kind = self::methodKind($method);
        if ($kind === 'express') {
            $urgencyId = collect($selectedUrgencyIds)
                ->map(fn ($id) => (int) $id)
                ->first(fn (int $id) => $id > 0);
            $urgency = $urgencyId
                ? $urgencies->first(fn ($option) => (int) data_get($option, 'id') === $urgencyId)
                : null;

            return [
                'method_id' => $selectedId,
                'urgency_id' => $urgencyId,
                'kind' => 'express',
                'title' => 'Standard Express Shipping — '.($urgency ? self::urgencyLabel($urgency) : 'Normal'),
                'estimate' => $urgency ? self::urgencyEstimate($urgency) : 'About 7 days',
            ];
        }

        return [
            'method_id' => $selectedId,
            'urgency_id' => null,
            'kind' => $kind,
            'title' => self::methodLabel($method),
            'estimate' => self::methodEstimate($method),
        ];
    }

    /**
     * Backward-compatible wrapper for any callers left open across deployment.
     * New Create Order renders use selectedCard() because selection is singular.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function selectedCards(
        Collection $methods,
        Collection $urgencies,
        array $selectedMethodIds,
        array $selectedUrgencyIds,
    ): array {
        $card = self::selectedCard($methods, $urgencies, $selectedMethodIds, $selectedUrgencyIds);

        return $card ? [$card] : [];
    }
}
