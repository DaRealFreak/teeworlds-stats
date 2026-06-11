import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { viteStaticCopy } from 'vite-plugin-static-copy';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/assets/sass/app.scss',
                'resources/assets/sass/font-awesome.scss',
                'resources/assets/js/app.js',
            ],
            refresh: true,
        }),
        viteStaticCopy({
            targets: [
                {
                    src: 'node_modules/font-awesome/fonts/*',
                    dest: 'fonts',
                    rename: { stripBase: true },
                },
            ],
        }),
    ],
    css: {
        preprocessorOptions: {
            scss: {
                loadPaths: ['node_modules'],
            },
        },
    },
});
