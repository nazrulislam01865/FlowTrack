<?php

namespace App\Support;

/** Workspace-owned copyright attribution for the application and public pages. */
final class SystemFooter
{
    public static function defaults(): array
    {
        return [
            'enabled' => true,
            'copyright' => '© '.date('Y'),
            'owner' => 'Itqan Consulting',
            'website_url' => 'https://itqanconsulting.com',
            'suffix' => 'All rights reserved.',
        ];
    }

    public static function normalize(?array $settings): array
    {
        $defaults = self::defaults();
        if ($settings === null) return $defaults;

        $normalized = $defaults;
        $normalized['enabled'] = (bool) ($settings['enabled'] ?? $defaults['enabled']);
        foreach (['copyright', 'owner', 'suffix'] as $field) {
            $value = $settings[$field] ?? $defaults[$field];
            $normalized[$field] = is_string($value) ? trim($value) : $defaults[$field];
        }

        $url = $settings['website_url'] ?? $defaults['website_url'];
        $normalized['website_url'] = is_string($url)
            && preg_match('~^https?://~i', $url)
            && filter_var($url, FILTER_VALIDATE_URL) ? $url : '';

        return $normalized;
    }
}
