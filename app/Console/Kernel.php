<?php

namespace App\Console;

use App\Console\Commands\UpdateDailySummary;
use App\Console\Commands\UpdateData;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Spatie\ResponseCache\Commands\Clear;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        UpdateData::class,
        UpdateDailySummary::class
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command(UpdateData::class)->everyTenMinutes()
            ->sendOutputTo('storage/logs/update_data_' . (int)time() . '.log')
            ->after(function () {
                Artisan::call(UpdateDailySummary::class);
                Artisan::call(Clear::class);
            });
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
