<?php

use Faker\Generator as Faker;

$factory->define(\App\Models\PlayerMods::class, function (Faker $faker) {
    return [
        'mod' => $faker->streetName(),
        'times' => random_int(0, 99),
    ];
});
