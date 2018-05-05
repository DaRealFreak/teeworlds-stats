<?php

use Faker\Generator as Faker;

$factory->define(\App\Models\PlayerMap::class, function (Faker $faker) {
    return [
        'map' => $faker->streetName(),
        'times' => random_int(0, 99),
    ];
});
