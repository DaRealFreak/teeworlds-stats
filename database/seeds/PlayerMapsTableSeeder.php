<?php

use Illuminate\Database\Seeder;

class PlayerMapsTableSeeder extends Seeder
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
            $tee->maps()->save(factory(App\Models\PlayerMaps::class)->make());
        });
    }
}
