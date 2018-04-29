<?php

use Faker\Generator as Faker;

$factory->define(\App\Models\ServerMaps::class, function (Faker $faker) {
    return [
        'map' => $faker->name(),
        'times' => random_int(0, 99),
    ];
});
