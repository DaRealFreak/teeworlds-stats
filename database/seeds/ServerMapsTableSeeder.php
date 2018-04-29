<?php

use Illuminate\Database\Seeder;

class ServerMapsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        factory(App\Models\Server::class, 10)->create()->each(function ($server) {
            /** @var \App\Models\Server $server */
            $server->maps()->save(factory(App\Models\ServerMaps::class)->make());
        });
    }
}
