<?php

namespace App\Services;

class ProductPriceTableParser
{
    /**
     * Keep the original public contract: return the primary product-price breakpoints.
     *
     * @return array<int, array{quantity:int, price:float}>
     */
    public function parse(?string $text): array
    {
        return $this->parseTable($text)['price_breakpoints'];
    }

    /**
     * Parse supported Excel price-table layouts into one normalized structure.
     *
     * Supported layouts:
     * 1. Vertical: Quantity | Product price, one breakpoint per row.
     * 2. Horizontal: Quantity across columns, Product price on the next row.
     * 3. Supplier matrix: leading descriptor columns (for example IID code / Size),
     *    quantity breakpoints across the header, one or more product rows, and an
     *    optional Remote Area charge / Remote surcharge row. The first normal
     *    product row is used as this FlowTrack product's base price row.
     *
     * @return array{
     *   price_breakpoints: array<int, array{quantity:int, price:float}>,
     *   remote_surcharge_breakpoints: array<int, array{quantity:int, price:float}>,
     *   format: string
     * }
     */
    public function parseTable(?string $text): array
    {
        $rows = $this->rows((string) $text);
        if ($rows === []) {
            return $this->emptyResult();
        }

        $vertical = $this->parseVertical($rows);
        if ($vertical !== []) {
            return [
                'price_breakpoints' => $this->normalize($vertical),
                'remote_surcharge_breakpoints' => $this->normalize($this->parseRemoteSurchargeForVertical($rows)),
                'format' => 'vertical',
            ];
        }

        $horizontal = $this->parseHorizontal($rows);
        if ($horizontal !== []) {
            return [
                'price_breakpoints' => $this->normalize($horizontal),
                'remote_surcharge_breakpoints' => $this->normalize($this->parseRemoteSurchargeForHorizontal($rows)),
                'format' => 'horizontal',
            ];
        }

        $matrix = $this->parseSupplierMatrix($rows);
        if ($matrix['price_breakpoints'] !== []) {
            return $matrix;
        }

        // Header-less Excel paste fallback: prefer the orientation that produces
        // the larger set of valid quantity/price pairs.
        $fallbackVertical = $this->parseFallbackVertical($rows);
        $fallbackHorizontal = $this->parseFallbackHorizontal($rows);

        $pairs = count($fallbackVertical) >= count($fallbackHorizontal)
            ? $fallbackVertical
            : $fallbackHorizontal;

        $useVerticalFallback = count($fallbackVertical) >= count($fallbackHorizontal);

        return [
            'price_breakpoints' => $this->normalize($pairs),
            'remote_surcharge_breakpoints' => $this->normalize(
                $useVerticalFallback
                    ? $this->parseFallbackVerticalRemoteSurcharge($rows)
                    : $this->parseFallbackHorizontalRemoteSurcharge($rows)
            ),
            'format' => $pairs === [] ? '' : ($useVerticalFallback ? 'vertical' : 'horizontal'),
        ];
    }

    /** @return array{price_breakpoints:array,remote_surcharge_breakpoints:array,format:string} */
    private function emptyResult(): array
    {
        return [
            'price_breakpoints' => [],
            'remote_surcharge_breakpoints' => [],
            'format' => '',
        ];
    }

    /** @return array<int, array<int, string>> */
    private function rows(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $rows = [];

        foreach ($lines as $line) {
            $line = trim(str_replace("\xC2\xA0", ' ', (string) $line));
            if ($line === '') {
                continue;
            }

            if (str_contains($line, "\t")) {
                $cells = explode("\t", $line);
            } elseif (preg_match('/^(Quantity|Qty|Product price|Supplier price|Client price|Price)\s+([0-9.,\s]+)$/iu', $line, $matches)) {
                // Two-row horizontal Excel tables can also arrive space-separated.
                // Treat each numeric token as a distinct tier instead of joining
                // "10 20 30" into the erroneous quantity 102030.
                $cells = array_merge([$matches[1]], preg_split('/\s+/u', trim($matches[2])) ?: []);
            } elseif (str_contains($line, ',')) {
                $cells = str_getcsv($line);
            } else {
                $cells = preg_split('/\s{2,}/u', $line) ?: [];
                if (count($cells) < 2) {
                    $cells = preg_split('/\s+/u', $line) ?: [];
                }
            }

            // Also handle mixed-tab pastes such as "Quantity\t10 20 30".
            // Do not split descriptive columns in other supported Excel layouts.
            if (count($cells) > 1 && preg_match('/^(Quantity|Qty|Product price|Supplier price|Client price|Price)$/iu', trim((string) $cells[0]))) {
                $expanded = [$cells[0]];
                foreach (array_slice($cells, 1) as $cell) {
                    $part = trim((string) $cell);
                    if (preg_match('/^[0-9.,]+(?:\s+[0-9.,]+)+$/u', $part)) {
                        array_push($expanded, ...(preg_split('/\s+/u', $part) ?: []));
                    } else {
                        $expanded[] = $cell;
                    }
                }
                $cells = $expanded;
            }

            $cells = array_values(array_map(
                fn ($cell) => trim(str_replace("\xC2\xA0", ' ', (string) $cell)),
                $cells
            ));

            if (count(array_filter($cells, fn ($cell) => $cell !== '')) > 0) {
                $rows[] = $cells;
            }
        }

        return $rows;
    }

    /** @return array{row:int,column:int}|null */
    private function header(array $rows, string $type): ?array
    {
        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $cell) {
                $header = $this->normalizeHeader($cell);

                if ($type === 'quantity' && ($header === 'quantity' || $header === 'qty' || str_contains($header, 'quantity'))) {
                    return ['row' => $rowIndex, 'column' => $columnIndex];
                }

                if ($type === 'price' && ($header === 'price' || $header === 'product price' || str_contains($header, 'product price') || str_ends_with($header, ' price'))) {
                    return ['row' => $rowIndex, 'column' => $columnIndex];
                }
            }
        }

        return null;
    }

    /** @return array<int, array{quantity:int, price:float}> */
    private function parseVertical(array $rows): array
    {
        $quantity = $this->header($rows, 'quantity');
        $price = $this->header($rows, 'price');

        if (! $quantity || ! $price || $quantity['row'] !== $price['row']) {
            return [];
        }

        $pairs = [];
        for ($row = $quantity['row'] + 1; $row < count($rows); $row++) {
            $quantityValue = $this->number($rows[$row][$quantity['column']] ?? null);
            $priceValue = $this->number($rows[$row][$price['column']] ?? null);

            if ($quantityValue !== null && $priceValue !== null) {
                $pairs[] = ['quantity' => (int) round($quantityValue), 'price' => $priceValue];
            }
        }

        return $pairs;
    }

    /** @return array<int, array{quantity:int, price:float}> */
    private function parseRemoteSurchargeForVertical(array $rows): array
    {
        $quantity = $this->header($rows, 'quantity');
        if (! $quantity) {
            return [];
        }

        $remote = null;
        foreach ($rows[$quantity['row']] ?? [] as $columnIndex => $cell) {
            $header = $this->normalizeHeader($cell);
            if ((str_contains($header, 'remote') && (str_contains($header, 'surcharge') || str_contains($header, 'charge') || str_contains($header, 'area')))
                || $header === 'remote surcharge') {
                $remote = ['row' => $quantity['row'], 'column' => $columnIndex];
                break;
            }
        }

        if (! $remote) {
            return [];
        }

        $pairs = [];
        for ($row = $quantity['row'] + 1; $row < count($rows); $row++) {
            $quantityValue = $this->number($rows[$row][$quantity['column']] ?? null);
            $remoteValue = $this->number($rows[$row][$remote['column']] ?? null);

            if ($quantityValue !== null && $remoteValue !== null) {
                $pairs[] = ['quantity' => (int) round($quantityValue), 'price' => $remoteValue];
            }
        }

        return $pairs;
    }

    /** @return array<int, array{quantity:int, price:float}> */
    private function parseHorizontal(array $rows): array
    {
        $quantity = $this->header($rows, 'quantity');
        $price = $this->header($rows, 'price');

        if (! $quantity || ! $price || $quantity['row'] === $price['row']) {
            return [];
        }

        $pairs = [];
        $startColumn = max($quantity['column'], $price['column']) + 1;
        $maxColumns = max(count($rows[$quantity['row']] ?? []), count($rows[$price['row']] ?? []));

        for ($column = $startColumn; $column < $maxColumns; $column++) {
            $quantityValue = $this->number($rows[$quantity['row']][$column] ?? null);
            $priceValue = $this->number($rows[$price['row']][$column] ?? null);

            if ($quantityValue !== null && $priceValue !== null) {
                $pairs[] = ['quantity' => (int) round($quantityValue), 'price' => $priceValue];
            }
        }

        return $pairs;
    }

    /** @return array<int, array{quantity:int, price:float}> */
    private function parseRemoteSurchargeForHorizontal(array $rows): array
    {
        $quantity = $this->header($rows, 'quantity');
        if (! $quantity) {
            return [];
        }

        foreach ($rows as $row) {
            if (! $this->isRemoteSurchargeRow($row)) {
                continue;
            }

            $pairs = [];
            for ($column = $quantity['column'] + 1; $column < count($rows[$quantity['row']] ?? []); $column++) {
                $quantityValue = $this->number($rows[$quantity['row']][$column] ?? null);
                $priceValue = $this->number($row[$column] ?? null);
                if ($quantityValue !== null && $priceValue !== null) {
                    $pairs[] = ['quantity' => (int) round($quantityValue), 'price' => $priceValue];
                }
            }
            return $pairs;
        }

        return [];
    }

    /**
     * Parse the user's real supplier layout, e.g.:
     * IID code | Size | 50 | 100 | 150 | ...
     * LPY12    | ...  | .95| .55 | .46 | ...
     * LPY58    | ...  | .96| .56 | .47 | ...
     * Remote Area charge | | .70 | .35 | .25 | ...
     *
     * @return array{price_breakpoints:array,remote_surcharge_breakpoints:array,format:string}
     */
    private function parseSupplierMatrix(array $rows): array
    {
        $quantityHeaderRow = null;
        $quantityColumns = [];

        foreach ($rows as $rowIndex => $row) {
            $candidate = [];
            foreach ($row as $columnIndex => $cell) {
                $value = $this->number($cell);
                if ($value === null || $value <= 0 || abs($value - round($value)) > 0.000001) {
                    continue;
                }
                $candidate[$columnIndex] = (int) round($value);
            }

            if (count($candidate) < 3) {
                continue;
            }

            $values = array_values($candidate);
            $strictlyIncreasing = true;
            for ($i = 1; $i < count($values); $i++) {
                if ($values[$i] <= $values[$i - 1]) {
                    $strictlyIncreasing = false;
                    break;
                }
            }

            if ($strictlyIncreasing) {
                $quantityHeaderRow = $rowIndex;
                $quantityColumns = $candidate;
                break;
            }
        }

        if ($quantityHeaderRow === null || $quantityColumns === []) {
            return $this->emptyResult();
        }

        $basePairs = [];
        $remotePairs = [];

        for ($rowIndex = $quantityHeaderRow + 1; $rowIndex < count($rows); $rowIndex++) {
            $row = $rows[$rowIndex];
            $pairs = [];

            foreach ($quantityColumns as $columnIndex => $quantity) {
                $price = $this->number($row[$columnIndex] ?? null);
                if ($price !== null && $price >= 0) {
                    $pairs[] = ['quantity' => $quantity, 'price' => $price];
                }
            }

            if (count($pairs) < 2) {
                continue;
            }

            if ($this->isRemoteSurchargeRow($row)) {
                $remotePairs = $pairs;
                continue;
            }

            if ($this->isRemarkRow($row)) {
                continue;
            }

            // The user wants one product only. When the supplier sheet contains
            // several product rows, use the first normal price row and ignore the
            // remaining variants rather than making the Product form more complex.
            if ($basePairs === []) {
                $basePairs = $pairs;
            }
        }

        return [
            'price_breakpoints' => $this->normalize($basePairs),
            'remote_surcharge_breakpoints' => $this->normalize($remotePairs),
            'format' => $basePairs === [] ? '' : 'supplier_matrix',
        ];
    }

    private function isRemoteSurchargeRow(array $row): bool
    {
        $label = $this->normalizeHeader(implode(' ', array_slice($row, 0, 3)));

        return str_contains($label, 'remote')
            && (str_contains($label, 'charge') || str_contains($label, 'surcharge') || str_contains($label, 'area'));
    }

    private function isRemarkRow(array $row): bool
    {
        $label = $this->normalizeHeader(implode(' ', array_slice($row, 0, 3)));
        return str_starts_with($label, 'remark') || str_starts_with($label, 'note');
    }

    /** @return array<int, array{quantity:int, price:float}> */
    private function parseFallbackVertical(array $rows): array
    {
        $pairs = [];

        foreach ($rows as $row) {
            $numbers = array_values(array_filter(array_map(fn ($cell) => $this->number($cell), $row), fn ($value) => $value !== null));
            if (count($numbers) >= 2) {
                $pairs[] = ['quantity' => (int) round($numbers[0]), 'price' => (float) $numbers[1]];
            }
        }

        return $pairs;
    }

    /** @return array<int, array{quantity:int, price:float}> */
    private function parseFallbackVerticalRemoteSurcharge(array $rows): array
    {
        $pairs = [];

        foreach ($rows as $row) {
            $numbers = array_values(array_filter(
                array_map(fn ($cell) => $this->number($cell), $row),
                fn ($value) => $value !== null
            ));

            if (count($numbers) >= 3) {
                $pairs[] = [
                    'quantity' => (int) round($numbers[0]),
                    'price' => (float) $numbers[2],
                ];
            }
        }

        return $pairs;
    }

    /** @return array<int, array{quantity:int, price:float}> */
    private function parseFallbackHorizontal(array $rows): array
    {
        if (count($rows) < 2) {
            return [];
        }

        $quantities = array_values(array_filter(array_map(fn ($cell) => $this->number($cell), $rows[0]), fn ($value) => $value !== null));
        $prices = array_values(array_filter(array_map(fn ($cell) => $this->number($cell), $rows[1]), fn ($value) => $value !== null));
        $length = min(count($quantities), count($prices));

        $pairs = [];
        for ($index = 0; $index < $length; $index++) {
            $pairs[] = ['quantity' => (int) round($quantities[$index]), 'price' => (float) $prices[$index]];
        }

        return $pairs;
    }

    /** @return array<int, array{quantity:int, price:float}> */
    private function parseFallbackHorizontalRemoteSurcharge(array $rows): array
    {
        if (count($rows) < 3) {
            return [];
        }

        $quantities = array_values(array_filter(
            array_map(fn ($cell) => $this->number($cell), $rows[0]),
            fn ($value) => $value !== null
        ));
        $remote = array_values(array_filter(
            array_map(fn ($cell) => $this->number($cell), $rows[2]),
            fn ($value) => $value !== null
        ));
        $length = min(count($quantities), count($remote));

        $pairs = [];
        for ($index = 0; $index < $length; $index++) {
            $pairs[] = [
                'quantity' => (int) round($quantities[$index]),
                'price' => (float) $remote[$index],
            ];
        }

        return $pairs;
    }

    /** @param array<int, array{quantity:int, price:float}> $pairs */
    private function normalize(array $pairs): array
    {
        $unique = [];

        foreach ($pairs as $pair) {
            $quantity = (int) ($pair['quantity'] ?? 0);
            $price = (float) ($pair['price'] ?? -1);

            if ($quantity <= 0 || $price < 0 || ! is_finite($price)) {
                continue;
            }

            $unique[$quantity] = [
                'quantity' => $quantity,
                'price' => round($price, 6),
            ];
        }

        ksort($unique, SORT_NUMERIC);

        return array_values($unique);
    }

    private function normalizeHeader(mixed $value): string
    {
        $value = strtolower(trim(str_replace("\xC2\xA0", ' ', (string) $value)));
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function number(mixed $value): ?float
    {
        $value = trim(str_replace("\xC2\xA0", ' ', (string) $value));
        if ($value === '') {
            return null;
        }

        if (preg_match('/\d\s+\d/u', $value)
            && ! preg_match('/^-?\d{1,3}(?: \d{3})+(?:\.\d+)?$/u', $value)) {
            return null; // Never turn several pasted tiers into one large number.
        }

        $value = str_replace([',', '$', '€', '£', ' '], '', $value);
        $value = preg_replace('/[^0-9.\-]/', '', $value) ?? '';

        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }
}
