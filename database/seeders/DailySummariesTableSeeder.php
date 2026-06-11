<?php

namespace Database\Seeders;

use App\Models\DailySummary;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class DailySummariesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Seeds DailySummary rows for today and the past 29 days so that:
     *   - /general does not violate the NOT NULL constraints on the 6 numeric
     *     columns when DailySummary::firstOrCreate(['date' => Carbon::today()])
     *     tries to create a new row.
     *   - The general page chart has 30 days of historical data to display.
     */
    public function run(): void
    {
        for ($daysAgo = 29; $daysAgo >= 0; $daysAgo--) {
            $date = Carbon::today()->subDays($daysAgo);

            DailySummary::firstOrCreate(
                ['date' => $date->toDateString()],
                [
                    'players_online_peak' => rand(5, 50),
                    'players_online'      => rand(1, 30),
                    'clans_online_peak'   => rand(1, 10),
                    'clans_online'        => rand(0, 8),
                    'servers_online_peak' => rand(3, 15),
                    'servers_online'      => rand(1, 10),
                ]
            );
        }
    }
}
