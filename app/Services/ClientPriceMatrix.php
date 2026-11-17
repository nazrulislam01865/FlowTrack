<?php

namespace App\Services;

use InvalidArgumentException;

/** Editable client selling-price columns backed by existing quantity/price breakpoints. */
final class ClientPriceMatrix
{
    public function blankTier(): array
    {
        return ['quantity' => '', 'price' => ''];
    }

    /** @param array<int,array<string,mixed>> $breakpoints */
    public function editableTiers(array $breakpoints): array
    {
        $tiers = [];
        foreach ($breakpoints as $row) {
            if (! is_array($row) || ! isset($row['quantity'], $row['price'])) continue;
            $tiers[] = ['quantity' => (string) $row['quantity'], 'price' => (string) $row['price']];
        }

        return $tiers ?: [$this->blankTier()];
    }

    /**
     * @param array<int,mixed> $tiers
     * @return array<int,array{quantity:int,price:float}>
     */
    public function parseEditableTiers(array $tiers): array
    {
        if (count($tiers) > 100) {
            throw new InvalidArgumentException('Maximum 100 quantity columns are allowed.');
        }

        $prices = [];
        foreach ($tiers as $tier) {
            if (! is_array($tier)) {
                throw new InvalidArgumentException('Invalid client price column.');
            }
            $quantity = trim((string) ($tier['quantity'] ?? ''));
            $price = trim((string) ($tier['price'] ?? ''));

            // An entirely empty column is a placeholder, not saved price data.
            if ($quantity === '' && $price === '') continue;
            if (! ctype_digit($quantity) || (int) $quantity < 1 || (int) $quantity > 100000000
                || $price === '' || ! is_numeric($price) || ! is_finite((float) $price)
                || (float) $price < 0 || (float) $price > 999999999.99) {
                throw new InvalidArgumentException('Each populated column needs a positive whole-number quantity and a valid client unit price.');
            }
            $value = (int) $quantity;
            if (isset($prices[$value])) {
                throw new InvalidArgumentException('Each client pricing quantity must be unique.');
            }
            $prices[$value] = ['quantity' => $value, 'price' => (float) $price];
        }
        ksort($prices, SORT_NUMERIC);
        return array_values($prices);
    }

    /** @param array<int,array{quantity:int,price:float}> $prices */
    public function toLegacyTable(array $prices): string
    {
        if ($prices === []) return '';

        return "Quantity\t".implode("\t", array_column($prices, 'quantity'))
            ."\nProduct price\t".implode("\t", array_column($prices, 'price'));
    }
}
