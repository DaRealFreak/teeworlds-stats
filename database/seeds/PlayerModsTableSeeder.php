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
        factory(App\Models\Tee::class, 10)->create()->each(function ($tee) {
            /** @var \App\Models\Tee $tee */
            $tee->mods()->save(factory(App\Models\PlayerMods::class)->make());
        });
    }
}
