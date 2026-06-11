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
                {
                    // Copy the rectangular country flags to a stable /build/flags/4x3
                    // path; flag-icons is pointed there via $flag-icons-path so Sass
                    // doesn't have to rebase ~250 individual url() assets.
                    src: 'node_modules/flag-icons/flags/4x3/*',
                    dest: 'flags/4x3',
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
