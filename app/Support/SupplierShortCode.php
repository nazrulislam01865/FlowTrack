<?php

namespace App\Support;

final class SupplierShortCode
{
    public static function normalize(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') return '';

        $value = preg_replace('/\s+/', '', $value) ?? '';
        return strtoupper(substr($value, 0, 40));
    }

    /**
     * Generate a compact supplier code without performing any database lookup.
     * Examples: "Mandy Rose" => "MR", "babel 巴贝尔" => "BA".
     */
    public static function fromName(?string $name, ?int $fallbackId = null): string
    {
        preg_match_all('/[A-Za-z0-9]+/', trim((string) $name), $matches);
        $words = array_values(array_filter(
            array_map(fn ($word) => trim((string) $word), $matches[0] ?? []),
            fn ($word) => $word !== '',
        ));

        if (count($words) >= 2) {
            $initials = '';
            foreach (array_slice($words, 0, 4) as $word) {
                $initials .= substr($word, 0, 1);
            }
            return self::normalize($initials);
        }

        if (count($words) === 1) {
            $word = self::normalize($words[0]);
            if (strlen($word) <= 4) return $word;
            return substr($word, 0, 2);
        }

        return $fallbackId && $fallbackId > 0
            ? 'S'.str_pad((string) $fallbackId, 2, '0', STR_PAD_LEFT)
            : 'S';
    }

    public static function resolve(?string $storedCode, ?string $name, ?int $fallbackId = null): string
    {
        $stored = self::normalize($storedCode);
        return $stored !== '' ? $stored : self::fromName($name, $fallbackId);
    }
}
