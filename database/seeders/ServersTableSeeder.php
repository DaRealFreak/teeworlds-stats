<?php

namespace Database\Seeders;

use App\Models\Server;
use Illuminate\Database\Seeder;

class ServersTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Creates 10 servers.
     */
    public function run(): void
    {
        Server::factory()->count(10)->create();
    }
}
