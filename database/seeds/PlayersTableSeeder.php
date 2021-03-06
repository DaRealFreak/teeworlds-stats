<?php

use Illuminate\Database\Seeder;

class PlayersTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // 25 players without clan,
        // ClansTableSeeder will create players in the clans too,
        // PlayerStatusesTableSeeder will create players with create Players with Status Records
        factory(App\Models\Player::class, 25)->create()->each(function ($player) {
            /** @var \App\Models\Player $player */
            $player->maps()->saveMany(factory(App\Models\PlayerMap::class, random_int(1, 25))->make());
            $player->mods()->saveMany(factory(App\Models\PlayerMod::class, random_int(1, 25))->make());
            $player->stats()->save(factory(App\Models\PlayerStatus::class)->make());
        });
    }
}
