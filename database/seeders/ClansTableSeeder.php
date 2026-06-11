<?php

namespace Database\Seeders;

use App\Models\Clan;
use App\Models\Player;
use App\Models\PlayerClanHistory;
use Illuminate\Database\Seeder;

class ClansTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Creates 10 clans, each with between 1 and 15 players linked through a
     * PlayerClanHistory record (the join that backs Clan::players()).
     */
    public function run(): void
    {
        Clan::factory()->count(10)->create()->each(function (Clan $clan): void {
            Player::factory()->count(random_int(1, 15))->create()->each(function (Player $player) use ($clan): void {
                PlayerClanHistory::create([
                    'player_id' => $player->getAttribute('id'),
                    'clan_id'   => $clan->getAttribute('id'),
                ]);
            });
        });
    }
}
