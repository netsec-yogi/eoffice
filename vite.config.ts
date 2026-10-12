import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';

const port = Number(process.env.VITE_PORT ?? 5175);

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.ts'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: { base: null, includeAbsolute: false },
            },
        }),
        tailwindcss(),
    ],
    resolve: {
        alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) },
    },
    server: {
        // Runs inside the node container; reachable from the host browser.
        host: '0.0.0.0',
        port,
        strictPort: true,
        hmr: { host: 'localhost', port },
        cors: { origin: /^http:\/\/localhost(:\d+)?$/ },
        watch: { ignored: ['**/storage/framework/views/**', '**/vendor/**'] },
    },
});
