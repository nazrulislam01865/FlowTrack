import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/flowtrack-build.css',
                'resources/js/flowtrack-build.ts',
            ],
            refresh: [
                'resources/views/**',
                'public/flowtrack-dynamic.js',
            ],
        }),
    ],
});
