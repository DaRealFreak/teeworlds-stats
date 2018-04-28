<?php

use Illuminate\Database\Seeder;

class TeesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        /** @var \App\Models\Clan $clan */
        // 25 players without clan,
        // ClansTableSeeder will create players in the clans too,
        // PlayerStatusesTableSeeder will create players with create Tees with Status Records
        factory(App\Models\Tee::class, 25)->create();
    }
}
