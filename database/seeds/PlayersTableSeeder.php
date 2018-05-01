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
            $player->maps()->save(factory(App\Models\PlayerMaps::class)->make());
            $player->maps()->save(factory(App\Models\PlayerMods::class)->make());
            $player->maps()->save(factory(App\Models\PlayerStatus::class)->make());
        });
    }
}
