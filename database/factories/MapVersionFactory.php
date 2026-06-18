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
            // Map has no HasFactory — create a minimal row so the factory is self-contained when no
            // parent is supplied via ->for($map); callers that pass ->for($map) override map_id directly.
            'map_id' => Map::create(['name' => $this->faker->unique()->word()]),
            'crc' => dechex($this->faker->numberBetween(0x10000000, 0x7fffffff)),
            'size' => $this->faker->numberBetween(50_000, 800_000),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
