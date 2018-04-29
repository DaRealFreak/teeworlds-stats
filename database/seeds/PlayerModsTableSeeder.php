<?php

use Illuminate\Database\Seeder;

class PlayerModsTableSeeder extends Seeder
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
            $player->mods()->save(factory(App\Models\PlayerMods::class)->make());
        });
    }
}
