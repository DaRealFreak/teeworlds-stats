<?php

use Illuminate\Database\Seeder;

class PlayerStatusesTableSeeder extends Seeder
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
            $player->stats()->save(factory(App\Models\PlayerStatus::class)->make());
        });
    }
}
