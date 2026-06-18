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
            // Map has no HasFactory; default to a self-contained parent so a standalone
            // MapVersion::factory() still works. A closure (not a direct call) so it only fires
            // when no ->for($map) is supplied — ->for() overrides map_id before this would run,
            // leaving no orphan Map row.
            'map_id' => fn () => Map::create(['name' => $this->faker->unique()->word()])->getKey(),
            'crc' => dechex($this->faker->numberBetween(0x10000000, 0x7fffffff)),
            'size' => $this->faker->numberBetween(50_000, 800_000),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
