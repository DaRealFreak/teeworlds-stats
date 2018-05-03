<?php

use Illuminate\Database\Seeder;

class ClansTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // 10 clans with each between 1 and 15 players
        factory(App\Models\Clan::class, 10)->create()->each(function ($clan) {
            /** @var \App\Models\Clan $clan */
            $clan->players()->saveMany(factory(App\Models\Player::class, random_int(1, 15))->create()->each(function ($player) {
                /** @var \App\Models\Player $player */
                $player->maps()->save(factory(App\Models\PlayerMaps::class)->make());
                $player->mods()->save(factory(App\Models\PlayerMods::class)->make());
                $player->stats()->save(factory(App\Models\PlayerStatus::class)->make());
            }));
        });
    }
}
