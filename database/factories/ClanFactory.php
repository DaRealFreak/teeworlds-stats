<?php

use Faker\Generator as Faker;

$factory->define(\App\Models\Clan::class, function (Faker $faker) {
    return [
        'name' => $faker->name(),
        'introduction' => $faker->text(),
        'website' => $faker->url(),
    ];
});
