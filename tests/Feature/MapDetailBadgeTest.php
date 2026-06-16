<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MapDetailBadgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_detail_page_shows_a_queued_badge_for_a_pending_uncached_map(): void
    {
        Storage::fake('local');

        // Has an identity but no cached file, status still pending → Queued (the AliveFNG case: a hash,
        // recorded and awaiting acquisition).
        $map = Map::create(['name' => 'dlmap', 'sha256' => str_repeat('b', 64)]);
        MapVersion::create(['map_id' => $map->id, 'sha256' => str_repeat('b', 64), 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->get('/map/dlmap/')->assertOk()->assertSee('Queued')->assertDontSee('Unavailable');
    }

    public function test_detail_page_shows_a_server_only_badge_for_a_udp_only_map(): void
    {
        Storage::fake('local');

        // A version only a live host serves (not mirrored) → Server-only.
        $map = Map::create(['name' => 'udpmap', 'crc' => 'deadbeef']);
        MapVersion::create(['map_id' => $map->id, 'crc' => 'deadbeef', 'first_seen_at' => now(), 'last_seen_at' => now()])
            ->markStatus(MapVersion::STATUS_UDP_ONLY);

        $this->get('/map/udpmap/')->assertOk()->assertSee('Server-only');
    }

    public function test_detail_page_shows_a_cached_badge_when_the_file_is_on_disk(): void
    {
        Storage::fake('local');

        $sha = str_repeat('c', 64);
        $map = Map::create(['name' => 'cachedmap', 'sha256' => $sha]);
        MapVersion::create(['map_id' => $map->id, 'sha256' => $sha, 'first_seen_at' => now(), 'last_seen_at' => now()]);
        Storage::disk('local')->put('maps/cachedmap_' . $sha . '.map', 'BYTES');

        // disk wins over the pending status the version still carries — no Queued badge leaks through.
        $this->get('/map/cachedmap/')->assertOk()->assertSee('Cached')->assertDontSee('Queued');
    }

    public function test_detail_page_shows_an_unavailable_badge_for_a_keyless_hostless_map(): void
    {
        Storage::fake('local');

        Map::create(['name' => 'ghostmap']); // no identity, no host

        $this->get('/map/ghostmap/')->assertOk()->assertSee('Unavailable');
    }
}
