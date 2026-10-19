<?php

namespace App\Support;

/**
 * Stable fingerprint for the frontend shell currently published by FlowTrack.
 *
 * Livewire navigation keeps the existing JavaScript/CSS shell alive between
 * page visits. If a deployment happens while a browser tab is still open, the
 * next SPA navigation can otherwise combine old CSS/JS with new Blade markup.
 * The layout exposes this fingerprint as the query string of a stable tracked
 * asset so Livewire can force a real browser reload when the deployed build
 * changes.
 */
final class FrontendBuildVersion
{
    public static function current(): string
    {
        static $version = null;

        if (is_string($version)) {
            return $version;
        }

        // During `npm run dev` there may be no build manifest. Keep a stable
        // development marker so HMR is not interrupted by forced reloads.
        if (is_file(public_path('hot'))) {
            return $version = 'hot';
        }

        $manifest = public_path('build/manifest.json');
        if (! is_file($manifest)) {
            return $version = 'missing';
        }

        $parts = [];
        foreach ([
            $manifest,
            public_path('js/flowtrack-build-track.js'),
            public_path('js/flowtrack-sidebar-navigation.js'),
        ] as $file) {
            if (! is_file($file)) {
                continue;
            }

            $fileHash = hash_file('sha256', $file);
            if ($fileHash !== false) {
                $parts[] = $fileHash;
            }
        }

        if ($parts === []) {
            return $version = 'unreadable';
        }

        return $version = substr(hash('sha256', implode('|', $parts)), 0, 20);
    }
}
