<?php

namespace Database\Factories;

use App\Models\Map;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Map>
 */
class MapFactory extends Factory
{
    protected $model = Map::class;

    private static array $mapNames = [
        'dm1', 'ctf1', 'ctf2', 'ctf3', 'ctf4', 'ctf5', 'dm2', 'dm3', 'dm4', 'dm5',
        'dm6', 'dm7', 'dm8', 'desert_main', 'jungle_main', 'city', 'mountain', 'river',
        'arctic', 'nuclear', 'space', 'hell', 'heavens', 'iceworld', 'lavaworld',
    ];

    private static int $index = 0;

    public function definition(): array
    {
        $name = self::$mapNames[self::$index % count(self::$mapNames)];
        self::$index++;

        return [
            'name' => $name,
        ];
    }
}
