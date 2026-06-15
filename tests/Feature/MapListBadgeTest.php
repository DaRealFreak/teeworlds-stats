<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapVersion;
use App\Models\Mod;
use App\Models\Server;
use App\Models\ServerHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MapListBadgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_maps_list_shows_an_availability_badge_for_each_state(): void
    {
        Storage::fake('local');

        // Cached: the current version's .map file is on disk
        $sha = str_repeat('a', 64);
        $cached = Map::create(['name' => 'cached-map', 'sha256' => $sha]);
        MapVersion::create(['map_id' => $cached->id, 'sha256' => $sha, 'first_seen_at' => now(), 'last_seen_at' => now()]);
        Storage::disk('local')->put('maps/cached-map_' . $sha . '.map', 'BYTES');

        // Cached via the LEGACY map-keyed path: a file on disk but NO MapVersion row (pre-migration cache)
        $legacySha = str_repeat('e', 64);
        Map::create(['name' => 'legacy-cached-map', 'sha256' => $legacySha]);
        Storage::disk('local')->put('maps/legacy-cached-map_' . $legacySha . '.map', 'BYTES');

        // Downloadable: has an identity but no cached file
        Map::create(['name' => 'downloadable-map', 'sha256' => str_repeat('b', 64)]);

        // Unavailable: no identity, no host
        Map::create(['name' => 'unavailable-map']);

        // Downloadable via a recorded host (keyless but hosted)
        $hosted = Map::create(['name' => 'hosted-keyless-map']);
        $mod = Mod::create(['name' => 'dm']);
        $server = Server::factory()->create(['flavor' => 'vanilla_06']);
        ServerHistory::create([
            'weekday' => 1, 'hour' => 12, 'continuous' => true,
            'server_id' => $server->id, 'map_id' => $hosted->id, 'mod_id' => $mod->id, 'minutes' => 5,
        ]);

        $response = $this->get('/maps')->assertOk()
            ->assertSee('Cached')
            ->assertSee('Downloadable')
            ->assertSee('Unavailable');

        // Maps render alphabetically (cached-map, downloadable-map, hosted-keyless-map, legacy-cached-map,
        // unavailable-map); each map's badge follows its name and the next map differs, so an
        // "<badge> between this name and the next name" check pins the specific state and is regression-proof.
        $response->assertSeeInOrder(['hosted-keyless-map', 'Downloadable', 'legacy-cached-map']); // hosted keyless → Downloadable
        $response->assertSeeInOrder(['legacy-cached-map', 'Cached', 'unavailable-map']);          // legacy cache path → Cached
    }
}
