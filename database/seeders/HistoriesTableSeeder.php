<?php

namespace Database\Seeders;

use App\Models\Map;
use App\Models\Mod;
use App\Models\Player;
use App\Models\PlayerHistory;
use App\Models\Server;
use App\Models\ServerHistory;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class HistoriesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Seeds PlayerHistory and ServerHistory rows so that:
     *   - Player::totalHoursOnline(), chartPlayedMods(), and chartPlayedMaps() all
     *     return non-empty results (required by /tees list view).
     *   - Server::totalHoursOnline(), chartPlayedMaps(), and chartPlayedMods() all
     *     return non-empty results (required by /servers list view).
     *
     * Each player and each server gets a handful of history rows spread across
     * different weekday/hour/map/mod combinations to make the charts meaningful.
     */
    public function run(): void
    {
        $players = Player::all();
        $servers = Server::all();
        $maps    = Map::all();
        $mods    = Mod::all();

        if ($maps->isEmpty() || $mods->isEmpty() || $players->isEmpty() || $servers->isEmpty()) {
            $this->command->warn('HistoriesTableSeeder: skipping — mods/maps/players/servers must be seeded first.');
            return;
        }

        $mapIds  = $maps->pluck('id')->toArray();
        $modIds  = $mods->pluck('id')->toArray();
        $mapCount = count($mapIds);
        $modCount = count($modIds);

        // Distribute servers across players for the player history foreign key
        $serverIds = $servers->pluck('id')->toArray();
        $serverCount = count($serverIds);

        // Seed PlayerHistory: 5 rows per player across varying hours/weekdays/maps/mods
        $playerHistoryRows = [];
        foreach ($players as $i => $player) {
            for ($j = 0; $j < 5; $j++) {
                $playerHistoryRows[] = [
                    'server_id'  => $serverIds[($i + $j) % $serverCount],
                    'player_id'  => $player->id,
                    'map_id'     => $mapIds[($i * 5 + $j) % $mapCount],
                    'mod_id'     => $modIds[($i * 5 + $j) % $modCount],
                    'weekday'    => ($i + $j) % 7,
                    'hour'       => ($i * 3 + $j * 4) % 24,
                    'continuous' => ($j % 2 === 0) ? 1 : 0,
                    'minutes'    => 15 + ($j * 10) + ($i % 5) * 5,
                    'created_at' => Carbon::today()->subDays($j)->toDateTimeString(),
                    'updated_at' => Carbon::today()->subDays($j)->toDateTimeString(),
                ];
            }
        }

        // Batch-insert in chunks to avoid oversized queries
        foreach (array_chunk($playerHistoryRows, 100) as $chunk) {
            PlayerHistory::insert($chunk);
        }

        // Seed ServerHistory: 5 rows per server across varying hours/weekdays/maps/mods
        $serverHistoryRows = [];
        foreach ($servers as $i => $server) {
            for ($j = 0; $j < 5; $j++) {
                $serverHistoryRows[] = [
                    'server_id'  => $server->id,
                    'map_id'     => $mapIds[($i * 5 + $j) % $mapCount],
                    'mod_id'     => $modIds[($i * 5 + $j) % $modCount],
                    'weekday'    => ($i + $j) % 7,
                    'hour'       => ($i * 3 + $j * 4) % 24,
                    'continuous' => ($j % 2 === 0) ? 1 : 0,
                    'minutes'    => 20 + ($j * 8) + ($i % 5) * 6,
                    'created_at' => Carbon::today()->subDays($j)->toDateTimeString(),
                    'updated_at' => Carbon::today()->subDays($j)->toDateTimeString(),
                ];
            }
        }

        foreach (array_chunk($serverHistoryRows, 100) as $chunk) {
            ServerHistory::insert($chunk);
        }
    }
}
