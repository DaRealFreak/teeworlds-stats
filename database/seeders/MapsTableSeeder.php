<?php

namespace Database\Seeders;

use App\Models\Map;
use Illuminate\Database\Seeder;

class MapsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Creates standard TeeWorlds maps so that PlayerHistory and ServerHistory
     * rows can reference valid map_id values.
     */
    public function run(): void
    {
        $maps = [
            'dm1', 'ctf1', 'ctf2', 'ctf3', 'ctf4', 'ctf5', 'dm2', 'dm3', 'dm4', 'dm5',
            'dm6', 'dm7', 'dm8', 'desert_main', 'jungle_main', 'city', 'mountain', 'river',
            'arctic', 'nuclear',
        ];

        foreach ($maps as $name) {
            Map::firstOrCreate(['name' => $name]);
        }
    }
}
