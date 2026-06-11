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
                    // Font Awesome and flag-icons are served as prebuilt stylesheets
                    // linked directly in the layout (not via Sass/Vite), so their many
                    // font/flag url()s never reach Vite's asset resolver and flood the
                    // build log. Both reference ../fonts and ../flags from /build/css.
                    src: 'node_modules/font-awesome/css/font-awesome.min.css',
                    dest: 'css',
                    rename: { stripBase: true },
                },
                {
                    src: 'node_modules/flag-icons/css/flag-icons.min.css',
                    dest: 'css',
                    rename: { stripBase: true },
                },
                {
                    // flag-icons.min.css references ../flags/4x3/*.svg → /build/flags/4x3.
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
                // Quiet deprecation warnings that originate inside dependencies only —
                // i.e. the Bootstrap framework's own internal @import chain, which we
                // cannot edit (Bootstrap 6 drops it). Our own stylesheets use @use.
                quietDeps: true,
            },
        },
    },
});
