import { defineConfig } from 'vite';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.dirname(fileURLToPath(import.meta.url));
const generated = path.resolve(root, 'resources/js/fleetman/generated');

export default defineConfig({
    publicDir: false,
    build: {
        outDir: path.resolve(root, 'public/js/dist'),
        emptyOutDir: true,
        minify: 'esbuild',
        sourcemap: false,
        rollupOptions: {
            input: {
                'fleetman-core': path.resolve(generated, 'core.js'),
                'fleetman-operations': path.resolve(generated, 'operations.js'),
                'fleetman-people': path.resolve(generated, 'people.js'),
                'fleetman-master': path.resolve(generated, 'master.js'),
                'fleetman-contracts': path.resolve(generated, 'contracts.js'),
                'fleetman-record-api': path.resolve(generated, 'record-api.js'),
            },
            output: {
                entryFileNames: '[name].min.js',
                chunkFileNames: '[name]-[hash].min.js',
                assetFileNames: '[name]-[hash][extname]',
            },
        },
    },
});
