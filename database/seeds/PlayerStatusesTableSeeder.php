<?php

use Illuminate\Database\Seeder;

class PlayerStatusesTableSeeder extends Seeder
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
            $tee->stats()->save(factory(App\Models\PlayerStatus::class)->make());
        });
    }
}
