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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
