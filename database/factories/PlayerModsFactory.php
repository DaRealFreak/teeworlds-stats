<?php

use Faker\Generator as Faker;

$factory->define(\App\Models\PlayerMods::class, function (Faker $faker) {
    return [
        'mod' => $faker->name(),
        'times' => random_int(0, 99),
    ];
});
