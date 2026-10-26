<?php

namespace App\Support;

/** Public supplier sign-in footer, managed only by the FlowTracker administrator. */
final class SupplierPortalFooter
{
    public static function defaults(): array
    {
        return [
            'copyright' => '© '.date('Y').' FlowTrack',
            'links' => [
                'privacy' => ['enabled' => true, 'label' => 'Privacy', 'url' => ''],
                'terms' => ['enabled' => true, 'label' => 'Terms', 'url' => ''],
                'help' => ['enabled' => true, 'label' => 'Help center', 'url' => ''],
            ],
        ];
    }

    public static function normalize(?array $settings): array
    {
        $default = self::defaults();
        if ($settings === null) return $default;
        $copyright = $settings['copyright'] ?? $default['copyright'];
        $default['copyright'] = is_string($copyright) ? trim($copyright) : $default['copyright'];
        foreach ($default['links'] as $key => $value) {
            $link = $settings['links'][$key] ?? [];
            if (! is_array($link)) continue;
            $default['links'][$key]['enabled'] = (bool) ($link['enabled'] ?? $value['enabled']);
            $default['links'][$key]['label'] = is_string($link['label'] ?? null) ? trim($link['label']) : $value['label'];
            $url = $link['url'] ?? '';
            // URLs are validated by the editor and bridge; re-check before rendering.
            $default['links'][$key]['url'] = is_string($url)
                && preg_match('~^https?://~i', $url)
                && filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
        }
        return $default;
    }
}
