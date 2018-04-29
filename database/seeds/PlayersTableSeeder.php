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
        factory(App\Models\Player::class, 25)->create();
    }
}
