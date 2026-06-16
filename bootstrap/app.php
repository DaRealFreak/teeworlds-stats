<?php

use App\Console\Commands\UpdateData;
use App\Console\Commands\UpdateDailySummary;
use App\Models\MapAcquisitionAttempt;
use App\Models\ServerMapBackoff;
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

        // The filler drains the uncached backlog unbounded, newest-first, as its own background process so it
        // never blocks the scrape; withoutOverlapping lets one long run span scrape cycles, runInBackground keeps
        // it off the scheduler's critical path. Its budget self-heals a crashed run on the next tick.
        $schedule->command('maps:archive-fill')
            ->everyTenMinutes()
            ->withoutOverlapping(90)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/map_fill.log'));

        $schedule->command('queue:prune-failed --hours=168')->weekly();

        // Keep the acquisition telemetry and the per-(host, map) UDP backoffs bounded (each model
        // prunes its own long-expired rows).
        $schedule->command('model:prune', ['--model' => [MapAcquisitionAttempt::class, ServerMapBackoff::class]])->daily();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
