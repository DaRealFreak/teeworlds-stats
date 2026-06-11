<?php

namespace Database\Seeders;

use App\Models\Player;
use Illuminate\Database\Seeder;

class PlayersTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Creates 25 clan-less players (ClansTableSeeder seeds players inside clans).
     */
    public function run(): void
    {
        Player::factory()->count(25)->create();
    }
}
