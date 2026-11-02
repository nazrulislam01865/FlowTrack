<?php

namespace App\Services\RemoteAreas;

/**
 * Compile ordered, possibly overlapping postal ranges into disjoint winner
 * intervals. PHP-only algorithm so it can be checked without Redis or Laravel.
 *
 * The earliest source ordinal always wins. Codes are positional identifiers:
 * leading zeroes and letter/digit shapes are never converted to integers.
 */
final class PostalRangeCompiler
{
    public static function key(?string $postalCode): string
    {
        return (string) preg_replace('/\s+/', '', strtoupper(trim((string) $postalCode)));
    }

    public static function shape(string $code): ?string
    {
        if ($code === '') return null;
        $shape = '';
        foreach (str_split($code) as $char) {
            if ($char >= '0' && $char <= '9') $shape .= 'D';
            elseif ($char >= 'A' && $char <= 'Z') $shape .= 'L';
            else return null;
        }
        return $shape;
    }

    public static function successor(string $code, string $shape): ?string
    {
        for ($i = strlen($code) - 1; $i >= 0; $i--) {
            $max = $shape[$i] === 'D' ? '9' : 'Z';
            if ($code[$i] === $max) continue;
            $next = $code;
            $next[$i] = chr(ord($code[$i]) + 1);
            for ($j = $i + 1; $j < strlen($code); $j++) {
                $next[$j] = $shape[$j] === 'D' ? '0' : 'A';
            }
            return $next;
        }
        return null;
    }

    public static function predecessor(string $code, string $shape): ?string
    {
        for ($i = strlen($code) - 1; $i >= 0; $i--) {
            $min = $shape[$i] === 'D' ? '0' : 'A';
            if ($code[$i] === $min) continue;
            $prev = $code;
            $prev[$i] = chr(ord($code[$i]) - 1);
            for ($j = $i + 1; $j < strlen($code); $j++) {
                $prev[$j] = $shape[$j] === 'D' ? '9' : 'Z';
            }
            return $prev;
        }
        return null;
    }

    /**
     * @param iterable<array{from:string,to:string,id:int,ordinal:int}> $rules
     * @return array<string,list<array{start:string,end:string,id:int}>>
     */
    public static function compile(iterable $rules): array
    {
        $events = [];
        $ids = [];
        foreach ($rules as $rule) {
            $from = $rule['from'];
            $to = $rule['to'];
            $shape = self::shape($from);
            if ($shape === null || $shape !== self::shape($to) || strcmp($from, $to) > 0) continue;

            $ordinal = $rule['ordinal'];
            $ids[$ordinal] = $rule['id'];
            $events[$shape][$from]['add'][] = $ordinal;
            $after = self::successor($to, $shape);
            if ($after !== null) $events[$shape][$after]['remove'][] = $ordinal;
        }

        $result = [];
        foreach ($events as $shape => $boundaries) {
            ksort($boundaries, SORT_STRING);
            $active = [];
            $heap = new \SplPriorityQueue();
            $heap->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
            $previousWinner = null;
            $previousStart = null;

            foreach ($boundaries as $boundary => $changes) {
                foreach ($changes['remove'] ?? [] as $ordinal) unset($active[$ordinal]);
                foreach ($changes['add'] ?? [] as $ordinal) {
                    $active[$ordinal] = true;
                    $heap->insert($ordinal, -$ordinal);
                }
                while (! $heap->isEmpty() && ! isset($active[$heap->top()])) $heap->extract();
                $winner = $heap->isEmpty() ? null : $heap->top();

                if ($winner === $previousWinner) continue;
                if ($previousWinner !== null && $previousStart !== null) {
                    $last = self::predecessor((string) $boundary, (string) $shape);
                    if ($last !== null && strcmp($previousStart, $last) <= 0) {
                        $result[$shape][] = ['start' => $previousStart, 'end' => $last, 'id' => $ids[$previousWinner]];
                    }
                }
                $previousStart = $winner !== null ? (string) $boundary : null;
                $previousWinner = $winner;
            }
            if ($previousWinner !== null && $previousStart !== null) {
                $result[$shape][] = [
                    'start' => $previousStart,
                    'end' => strtr($shape, ['D' => '9', 'L' => 'Z']),
                    'id' => $ids[$previousWinner],
                ];
            }
        }
        return $result;
    }

    /** Binary-search the compiled segments, useful for differential tests. */
    public static function find(array $segments, string $code): ?int
    {
        $shape = self::shape($code);
        if ($shape === null) return null;
        $list = $segments[$shape] ?? [];
        $lo = 0;
        $hi = count($list) - 1;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            $part = $list[$mid];
            if (strcmp($code, $part['start']) < 0) $hi = $mid - 1;
            elseif (strcmp($code, $part['end']) > 0) $lo = $mid + 1;
            else return $part['id'];
        }
        return null;
    }
}
