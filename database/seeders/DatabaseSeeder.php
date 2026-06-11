<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            // Independent entities first
            ClansTableSeeder::class,
            PlayersTableSeeder::class,
            ServersTableSeeder::class,
            ModsTableSeeder::class,
            MapsTableSeeder::class,
            // History rows depend on players, servers, mods, and maps
            HistoriesTableSeeder::class,
            // Daily summaries are independent of the above
            DailySummariesTableSeeder::class,
        ]);
    }
}
