<?php

use Illuminate\Database\Seeder;

class PlayerMapsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        factory(App\Models\Player::class, 10)->create()->each(function ($player) {
            /** @var \App\Models\Player $player */
            $player->maps()->save(factory(App\Models\PlayerMap::class)->make());
        });
    }
}
