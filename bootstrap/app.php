<?php

use App\Console\Commands\UpdateData;
use App\Console\Commands\UpdateDailySummary;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Artisan;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // replaces the former fideloper/proxy TrustProxies middleware
        $middleware->trustProxies(at: '*');
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command(UpdateData::class)
            ->everyTenMinutes()
            ->before(fn () => \Illuminate\Support\Facades\File::ensureDirectoryExists(storage_path('logs/update_data')))
            ->sendOutputTo(storage_path('logs/update_data/'.now()->timestamp.'.log'))
            ->after(function (): void {
                Artisan::call(UpdateDailySummary::class);
                Artisan::call('responsecache:clear');
            });

        // Steadily drain the historical uncached-version backlog into the local mirror. --http-only:
        // the every-10-min scrape already grabs currently-served maps over HTTP+UDP (InlineMapAcquirer),
        // so this hourly job only needs to mop up the mirrored versions no server currently runs — no
        // UDP dialling, so it never blocks on a slow host. runInBackground keeps it off the scrape's
        // critical path; withoutOverlapping guards against a slow run stacking. The scrape's own
        // responsecache:clear (every 10 min) refreshes the /maps availability badges afterwards.
        $schedule->command('maps:archive-backfill --http-only')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/map_backfill.log'));

        $schedule->command('queue:prune-failed --hours=168')->weekly();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
