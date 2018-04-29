<?php

use Faker\Generator as Faker;

$factory->define(\App\Models\ServerStatus::class, function (Faker $faker) {
    return [
        'hour_0' => random_int(0, 99),
        'hour_1' => random_int(0, 99),
        'hour_2' => random_int(0, 99),
        'hour_3' => random_int(0, 99),
        'hour_4' => random_int(0, 99),
        'hour_5' => random_int(0, 99),
        'hour_6' => random_int(0, 99),
        'hour_7' => random_int(0, 99),
        'hour_8' => random_int(0, 99),
        'hour_9' => random_int(0, 99),
        'hour_10' => random_int(0, 99),
        'hour_11' => random_int(0, 99),
        'hour_12' => random_int(0, 99),
        'hour_13' => random_int(0, 99),
        'hour_14' => random_int(0, 99),
        'hour_15' => random_int(0, 99),
        'hour_16' => random_int(0, 99),
        'hour_17' => random_int(0, 99),
        'hour_18' => random_int(0, 99),
        'hour_19' => random_int(0, 99),
        'hour_20' => random_int(0, 99),
        'hour_21' => random_int(0, 99),
        'hour_22' => random_int(0, 99),
        'hour_23' => random_int(0, 99),
        'monday' => random_int(0, 99),
        'tuesday' => random_int(0, 99),
        'wednesday' => random_int(0, 99),
        'thursday' => random_int(0, 99),
        'friday' => random_int(0, 99),
        'saturday' => random_int(0, 99),
        'sunday' => random_int(0, 99),
    ];
});
