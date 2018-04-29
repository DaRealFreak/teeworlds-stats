<?php

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $this->call([
            ClansTableSeeder::class,
            TeesTableSeeder::class,
            PlayerStatusesTableSeeder::class,
            PlayerMapsTableSeeder::class,
            PlayerModsTableSeeder::class,
            ServersTableSeeder::class,
            ServerStatusesTableSeeder::class,
            ServerMapsTableSeeder::class,
        ]);
    }
}
