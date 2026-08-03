import { defineConfig } from 'vite';

export default defineConfig({
    publicDir: false,
    build: {
        emptyOutDir: true,
        manifest: 'manifest.json',
        outDir: 'public/build',
        rollupOptions: {
            input: 'resources/css/flowtrack/app.css',
        },
    },
});
