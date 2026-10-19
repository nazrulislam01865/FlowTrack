<?php

namespace App\Support;

final class MasterColor
{
    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;

        if (preg_match('/^#?([0-9a-fA-F]{6})$/', $value, $matches)) {
            return '#'.strtoupper($matches[1]);
        }

        if (preg_match('/^#?([0-9a-fA-F]{3})$/', $value, $matches)) {
            $short = strtoupper($matches[1]);
            return '#'.$short[0].$short[0].$short[1].$short[1].$short[2].$short[2];
        }

        return null;
    }

    public static function defaultFor(string $type, ?string $name = null): string
    {
        $name = strtolower(trim((string) $name));

        if ($type === 'department') {
            if ($name === '') return '#2563EB';

            // Departments benefit from distinct, stable defaults so the team
            // report is useful immediately. An administrator can still replace
            // any generated color from Master Data -> Departments.
            $palette = [
                '#2563EB', '#7C3AED', '#0891B2', '#0F766E',
                '#16A34A', '#CA8A04', '#EA580C', '#DC2626',
                '#DB2777', '#9333EA', '#4F46E5', '#0369A1',
            ];

            return $palette[((int) sprintf('%u', crc32($name))) % count($palette)];
        }

        if (in_array($type, ['task_flag', 'order_task_flag', 'order_flag'], true)) {
            return match (true) {
                str_contains($name, 'block') => '#DC2626',
                str_contains($name, 'revision') => '#EA580C',
                str_contains($name, 'hold'), str_contains($name, 'wait') => '#D97706',
                str_contains($name, 'attention'), str_contains($name, 'urgent') => '#DC2626',
                default => '#DC2626',
            };
        }

        if (in_array($type, ['task_status', 'inquiry_task_status', 'order_task_status'], true)) {
            return match (true) {
                str_contains($name, 'complete'), str_contains($name, 'done') => '#16A34A',
                str_contains($name, 'cancel') => '#DC2626',
                str_contains($name, 'block') => '#DC2626',
                str_contains($name, 'revision') => '#EA580C',
                str_contains($name, 'review') => '#7C3AED',
                str_contains($name, 'wait'), str_contains($name, 'hold') => '#D97706',
                str_contains($name, 'progress') => '#2563EB',
                str_contains($name, 'ready') => '#0284C7',
                str_contains($name, 'not started') => '#64748B',
                default => '#2563EB',
            };
        }

        if ($type === 'priority') {
            return match (true) {
                str_contains($name, 'critical') => '#DC2626',
                str_contains($name, 'urgent') => '#EA580C',
                str_contains($name, 'high') => '#D97706',
                str_contains($name, 'medium'), str_contains($name, 'normal') => '#2563EB',
                str_contains($name, 'low') => '#64748B',
                default => '#2563EB',
            };
        }

        if ($type === 'inquiry_status') {
            return match (true) {
                str_contains($name, 'convert'), str_contains($name, 'complete'), str_contains($name, 'won') => '#16A34A',
                str_contains($name, 'dead'), str_contains($name, 'closed'), str_contains($name, 'cancel'), str_contains($name, 'lost') => '#DC2626',
                str_contains($name, 'wait'), str_contains($name, 'hold') => '#D97706',
                str_contains($name, 'review'), str_contains($name, 'quote') => '#7C3AED',
                str_contains($name, 'progress') => '#2563EB',
                str_contains($name, 'ready') => '#0284C7',
                str_contains($name, 'draft') => '#64748B',
                default => '#2563EB',
            };
        }

        return '#2563EB';
    }

    /**
     * CSS variables used by operational tables that tint a whole row from a
     * configured task color. The RGB fallback avoids depending on CSS
     * color-mix(), which previously made task colors effectively invisible in
     * some browser/table rendering combinations.
     */
    public static function taskRowStyle(?string $value): string
    {
        $color = self::normalize($value);
        if (!$color) return '';

        [$r, $g, $b] = self::rgb($color);

        return sprintf(
            '--task-row-color:%s;--task-row-bg:rgba(%d,%d,%d,.11);--task-row-hover-bg:rgba(%d,%d,%d,.17);',
            $color,
            $r,
            $g,
            $b,
            $r,
            $g,
            $b,
        );
    }

    public static function style(?string $value): string
    {
        $color = self::normalize($value);
        if (!$color) return '';

        [$r, $g, $b] = self::rgb($color);
        $text = self::textColor($r, $g, $b);

        return sprintf(
            '--ft-dynamic-color:%s;--ft-dynamic-bg:rgba(%d,%d,%d,.12);--ft-dynamic-border:rgba(%d,%d,%d,.34);--ft-dynamic-text:%s;--ft-master-color:%s;--ft-master-bg:rgba(%d,%d,%d,.12);--ft-master-border:rgba(%d,%d,%d,.34);--ft-master-text:%s;',
            $color,
            $r,
            $g,
            $b,
            $r,
            $g,
            $b,
            $text,
            $color,
            $r,
            $g,
            $b,
            $r,
            $g,
            $b,
            $text,
        );
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $color): array
    {
        $hex = ltrim($color, '#');

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    private static function textColor(int $r, int $g, int $b): string
    {
        // Bright colors such as yellow need a darker text tone when used on a
        // soft-tinted surface. Dark and medium colors can be used directly.
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
        if ($luminance <= 0.68) {
            return sprintf('#%02X%02X%02X', $r, $g, $b);
        }

        $factor = 0.52;
        return sprintf(
            '#%02X%02X%02X',
            (int) round($r * $factor),
            (int) round($g * $factor),
            (int) round($b * $factor),
        );
    }
}
