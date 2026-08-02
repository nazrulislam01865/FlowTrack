import { defineConfig } from 'vite';

export default defineConfig({
    publicDir: false,
    build: {
        // Keep generated CSS compatible with older still-supported Safari,
        // Firefox and Chromium releases used by tablets / managed desktops.
        cssTarget: ['chrome80', 'edge80', 'firefox78', 'safari13.1'],
        emptyOutDir: true,
        manifest: 'manifest.json',
        outDir: 'public/build',
        rollupOptions: {
            input: 'resources/css/flowtrack/app.css',
        },
    },
});
