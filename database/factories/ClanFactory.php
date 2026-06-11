<?php

namespace Database\Factories;

use App\Models\Clan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Clan>
 */
class ClanFactory extends Factory
{
    protected $model = Clan::class;

    public function definition(): array
    {
        return [
            'name'         => $this->faker->name(),
            'introduction' => $this->faker->text(),
            'website'      => $this->faker->url(),
        ];
    }
}
