<?php

namespace App\Console\Commands;

use App\Models\Map;
use App\Models\MapVersion;
use App\Models\ServerHistory;
use Illuminate\Console\Command;

/**
 * One-shot, idempotent backfill: turn each legacy map's single identity into a map_versions row and
 * link that map's server_histories to it. Run once after the map_versions migration ships; safe to
 * re-run (skips maps that already have a version).
 */
class BackfillMapVersions extends Command
{
    protected $signature = 'maps:backfill-versions';

    protected $description = 'Create a map_versions row from each legacy map identity and link histories';

    public function handle(): int
    {
        $count = 0;

        Map::query()
            ->where(fn ($q) => $q->whereNotNull('sha256')->orWhereNotNull('crc'))
            ->whereDoesntHave('versions')
            ->chunkById(500, function ($maps) use (&$count) {
                foreach ($maps as $map) {
                    $version = MapVersion::create([
                        'map_id' => $map->getAttribute('id'),
                        'sha256' => $map->getAttribute('sha256'),
                        'crc' => $map->getAttribute('crc'),
                        'size' => $map->getAttribute('size'),
                        'first_seen_at' => $map->getAttribute('updated_at'),
                        'last_seen_at' => $map->getAttribute('updated_at'),
                    ]);

                    ServerHistory::where('map_id', $map->getAttribute('id'))
                        ->whereNull('map_version_id')
                        ->update(['map_version_id' => $version->getAttribute('id')]);

                    $count++;
                }
            });

        $this->info("Backfilled {$count} map version(s).");

        return self::SUCCESS;
    }
}
