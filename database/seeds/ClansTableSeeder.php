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
        // 10 clans with each 3 player each
        factory(App\Models\Clan::class, 10)->create()->each(function ($clan) {
            /** @var \App\Models\Clan $clan */
            $clan->players()->save(factory(App\Models\Player::class)->make());
            $clan->players()->saveMany(factory(App\Models\Player::class, 2)->make());
        });
    }
}
