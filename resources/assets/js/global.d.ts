// The window.* seam between the bundled modules and the inline <script> blocks in the
// Blade views (general / detail.server / detail.clan / detail.player). Those inline scripts
// are plain JS, not part of this TypeScript build, so they reach the bundle only through
// these globals — keep them in sync with what app.ts / charthelper.ts / laravel.ts assign.

declare global {
    interface Window {
        // Chart.js constructor — the inline scripts on /general call `new Chart(...)` directly.
        Chart: typeof import('chart.js').Chart;
        // humanize-duration formatter — used by the inline scripts and the Chart tooltip callbacks.
        humanizeDuration: typeof import('humanize-duration').default;
        // chart factory the detail views drive (ChartHelper.lineChart / pieChart / radarChart / ...).
        ChartHelper: typeof import('./charthelper').ChartHelper;
        // identity passthrough so Blade {{ }} interpolation can sit inside valid JS: blade({{ $json }}).
        blade: <T>(value: T) => T;
    }
}

export {};
