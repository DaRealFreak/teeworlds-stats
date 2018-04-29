<?php

use Faker\Generator as Faker;

$factory->define(\App\Models\Player::class, function (Faker $faker) {
    return [
        'name' => $faker->name(),
        'country' => $faker->country()
    ];
});
