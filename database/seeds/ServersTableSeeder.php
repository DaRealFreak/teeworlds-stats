<?php

use Illuminate\Database\Seeder;

class ServersTableSeeder extends Seeder
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
            $server->maps()->saveMany(factory(App\Models\ServerMap::class, random_int(1, 25))->make());
            $server->stats()->save(factory(App\Models\ServerStatus::class)->make());
        });
    }
}
