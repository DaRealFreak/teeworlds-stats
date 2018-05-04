<?php

use Faker\Generator as Faker;

$factory->define(\App\Models\PlayerMaps::class, function (Faker $faker) {
    return [
        'map' => $faker->streetName(),
        'times' => random_int(0, 99),
    ];
});
