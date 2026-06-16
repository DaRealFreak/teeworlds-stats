<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapVersion;
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

        // Queued: a recorded version still pending acquisition (the default status)
        $queued = Map::create(['name' => 'queued-map', 'sha256' => str_repeat('b', 64)]);
        MapVersion::create(['map_id' => $queued->id, 'sha256' => str_repeat('b', 64), 'first_seen_at' => now(), 'last_seen_at' => now()]);

        // Server-only: a version only a live host serves (not mirrored)
        $serverOnly = Map::create(['name' => 'server-only-map', 'crc' => '0000ffff']);
        MapVersion::create(['map_id' => $serverOnly->id, 'crc' => '0000ffff', 'first_seen_at' => now(), 'last_seen_at' => now()])
            ->markStatus(MapVersion::STATUS_UDP_ONLY);

        // Unavailable: a version whose acquisition gave up
        $gone = Map::create(['name' => 'unavailable-map', 'crc' => 'ffff0000']);
        MapVersion::create(['map_id' => $gone->id, 'crc' => 'ffff0000', 'first_seen_at' => now(), 'last_seen_at' => now()])
            ->markStatus(MapVersion::STATUS_UNAVAILABLE);

        $response = $this->get('/maps')->assertOk()
            ->assertSee('Cached')
            ->assertSee('Queued')
            ->assertSee('Server-only')
            ->assertSee('Unavailable');

        // Maps render alphabetically (cached-map, legacy-cached-map, queued-map, server-only-map,
        // unavailable-map); each map's badge follows its name and the next map differs, so an
        // "<badge> between this name and the next name" check pins the specific state and is regression-proof.
        $response->assertSeeInOrder(['legacy-cached-map', 'Cached', 'queued-map']);          // legacy cache path → Cached
        $response->assertSeeInOrder(['queued-map', 'Queued', 'server-only-map']);            // pending version → Queued
        $response->assertSeeInOrder(['server-only-map', 'Server-only', 'unavailable-map']);  // udp_only version → Server-only
    }
}
