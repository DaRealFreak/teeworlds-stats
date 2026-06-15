<?php

namespace Database\Factories;

use App\Models\Map;
use App\Models\MapVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MapVersion>
 */
class MapVersionFactory extends Factory
{
    protected $model = MapVersion::class;

    public function definition(): array
    {
        return [
            'map_id' => Map::factory(),
            'crc' => dechex($this->faker->numberBetween(0x10000000, 0x7fffffff)),
            'size' => $this->faker->numberBetween(50_000, 800_000),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
