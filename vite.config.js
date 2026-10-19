import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/login.css',
                'resources/css/application/prelude.css',
                'resources/css/app.css',
                'resources/css/application/after-core.css',
                'resources/css/modules/dashboard/prototype.css',
                'resources/css/application/after-dashboard.css',
                'resources/css/application/shared-components.css',
                'resources/css/modules/orders/index.css',
                'resources/css/modules/work/index.css',
                'resources/css/modules/setup/index.css',
                'resources/css/modules/dashboard/layout.css',
                'resources/css/modules/documents/filters.css',
                'resources/css/modules/inquiries/filters.css',
                'resources/css/modules/clients/filters.css',
                'resources/theme/flowtrack/core.css',
                'resources/theme/flowtrack/theme.css',
                'resources/css/tracking.css',
                'resources/js/order-tracking.js',
                'resources/js/app.js',
            ],
            refresh: true,
        }),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
