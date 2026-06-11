<?php

namespace Database\Seeders;

use App\Models\Mod;
use Illuminate\Database\Seeder;

class ModsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Creates common TeeWorlds game mods so that PlayerHistory and ServerHistory
     * rows can reference valid mod_id values.
     */
    public function run(): void
    {
        $mods = [
            'vanilla', 'ddnet', 'ctf', 'dm', 'lms', 'tdm', 'race', 'catch',
            'zcatch', 'blockworlds', 'ninslash', 'gores', 'infection', 'fng', 'soccer',
        ];

        foreach ($mods as $name) {
            Mod::firstOrCreate(['name' => $name]);
        }
    }
}
