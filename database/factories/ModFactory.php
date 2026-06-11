<?php

namespace Database\Factories;

use App\Models\Mod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Mod>
 */
class ModFactory extends Factory
{
    protected $model = Mod::class;

    private static array $modNames = [
        'vanilla', 'ddnet', 'ctf', 'dm', 'lms', 'tdm', 'race', 'catch', 'zcatch',
        'blockworlds', 'ninslash', 'gores', 'teeworlds', 'infection', 'fng', 'soccer',
    ];

    private static int $index = 0;

    public function definition(): array
    {
        $name = self::$modNames[self::$index % count(self::$modNames)];
        self::$index++;

        return [
            'name' => $name,
        ];
    }
}
