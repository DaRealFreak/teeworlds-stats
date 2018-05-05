<?php

use Faker\Generator as Faker;

$factory->define(\App\Models\ServerMap::class, function (Faker $faker) {
    return [
        'map' => $faker->name(),
        'times' => random_int(0, 99),
    ];
});
