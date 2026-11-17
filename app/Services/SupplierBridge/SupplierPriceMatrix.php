<?php

namespace App\Services\SupplierBridge;

use Illuminate\Validation\ValidationException;

/** Optional supplier-specific, quantity-tiered charges; never part of approved unit costs. */
final class SupplierPriceMatrix
{
    public const CHARGE_FIELDS = ['remote_surcharge', 'standard_shipment', 'urgent_shipment'];

    /** @return array{quantity:string,price:string,remote_surcharge:string,standard_shipment:string,urgent_shipment:string} */
    public function blankTier(): array
    {
        return ['quantity' => '', 'price' => '', 'remote_surcharge' => '',
            'standard_shipment' => '', 'urgent_shipment' => ''];
    }

    /** @param array<int,array<string,mixed>> $prices @param array<int,array<string,mixed>> $charges */
    public function editableTiers(array $prices, array $charges): array
    {
        $byQuantity = [];
        foreach ($this->normalizeCharges($charges) as $charge) {
            $byQuantity[$charge['quantity']] = $charge;
        }
        $tiers = [];
        foreach ($prices as $row) {
            $quantity = (int) $row['quantity'];
            $tier = $this->blankTier();
            $tier['quantity'] = (string) $quantity;
            $tier['price'] = (string) $row['price'];
            foreach (self::CHARGE_FIELDS as $field) {
                $value = $byQuantity[$quantity][$field] ?? null;
                $tier[$field] = $value === null ? '' : (string) $value;
            }
            $tiers[] = $tier;
        }
        return $tiers ?: [$this->blankTier()];
    }

    /** @param array<int,mixed> $rows @return array<int,array<string,int|float>> */
    public function normalizeCharges(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['quantity']) || ! filter_var($row['quantity'], FILTER_VALIDATE_INT)) {
                continue;
            }
            $quantity = (int) $row['quantity'];
            if ($quantity < 1 || $quantity > 100000000) continue;
            $charges = ['quantity' => $quantity];
            foreach (self::CHARGE_FIELDS as $field) {
                $value = $row[$field] ?? null;
                if ($value !== null && $value !== '' && is_numeric($value)
                    && is_finite((float) $value) && (float) $value >= 0 && (float) $value <= 999999999.99) {
                    $charges[$field] = (float) $value;
                }
            }
            if (count($charges) > 1) $normalized[$quantity] = $charges;
        }
        ksort($normalized, SORT_NUMERIC);
        return array_values($normalized);
    }

    public function chargesVersion(array $charges): string
    {
        return hash('sha256', json_encode($this->normalizeCharges($charges), JSON_THROW_ON_ERROR));
    }

    /**
     * Read the directly editable matrix without silently dropping incomplete columns.
     * @param array<int,mixed> $tiers
     * @return array{rows:array<int,array{quantity:int,price:float}>,charges:array<int,array<string,int|float>>}
     */
    public function parseEditableTiers(array $tiers): array
    {
        if (count($tiers) > 100) $this->invalid('Maximum 100 quantity tiers.');
        $prices = [];
        $charges = [];
        foreach ($tiers as $tier) {
            if (! is_array($tier)) $this->invalid('Invalid quantity column.');
            $quantity = trim((string) ($tier['quantity'] ?? ''));
            $price = trim((string) ($tier['price'] ?? ''));
            $values = [];
            foreach (self::CHARGE_FIELDS as $field) {
                $values[$field] = trim((string) ($tier[$field] ?? ''));
            }
            if ($quantity === '' && $price === '' && count(array_filter($values, fn ($v) => $v !== '')) === 0) continue;
            if (! ctype_digit($quantity) || (int) $quantity < 1 || (int) $quantity > 100000000
                || $price === '' || ! is_numeric($price) || ! is_finite((float) $price)
                || (float) $price < 0 || (float) $price > 999999999.99) {
                $this->invalid('Each populated column needs a positive whole-number quantity and a valid supplier price.');
            }
            $q = (int) $quantity;
            if (isset($prices[$q])) $this->invalid('A quantity must appear only once per supplier.');
            $prices[$q] = ['quantity' => $q, 'price' => (float) $price];
            $extra = ['quantity' => $q];
            foreach ($values as $field => $value) {
                if ($value === '') continue;
                if (! is_numeric($value) || ! is_finite((float) $value)
                    || (float) $value < 0 || (float) $value > 999999999.99) {
                    $this->invalid('Remote surcharge and shipment charges must be non-negative numbers.');
                }
                $extra[$field] = (float) $value;
            }
            if (count($extra) > 1) $charges[$q] = $extra;
        }
        ksort($prices, SORT_NUMERIC);
        ksort($charges, SORT_NUMERIC);
        return ['rows' => array_values($prices), 'charges' => array_values($charges)];
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['productSupplierPrices' => $message]);
    }
}
