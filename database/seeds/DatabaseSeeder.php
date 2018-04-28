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
            ServersTableSeeder::class,
            ClansTableSeeder::class,
            TeesTableSeeder::class,
            PlayerStatusesTableSeeder::class,
        ]);
    }
}
