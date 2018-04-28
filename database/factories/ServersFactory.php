<?php

use Faker\Generator as Faker;

$factory->define(\App\Models\Server::class, function (Faker $faker) {
    return [
        'name' => $faker->firstName(),
        'ip' => sprintf("%d.%d.%d.%d", mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)),
        'port' => random_int(1, 65535),
    ];
});
