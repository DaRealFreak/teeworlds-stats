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

    public function test_detail_page_shows_a_downloadable_badge_for_an_uncached_map(): void
    {
        Storage::fake('local');

        // Has an identity but no cached file → Downloadable (the AliveFNG case: a hash, but not yet archived)
        $map = Map::create(['name' => 'dlmap', 'sha256' => str_repeat('b', 64)]);
        MapVersion::create(['map_id' => $map->id, 'sha256' => str_repeat('b', 64), 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->get('/map/dlmap/')->assertOk()->assertSee('Downloadable')->assertDontSee('Unavailable');
    }

    public function test_detail_page_shows_a_cached_badge_when_the_file_is_on_disk(): void
    {
        Storage::fake('local');

        $sha = str_repeat('c', 64);
        $map = Map::create(['name' => 'cachedmap', 'sha256' => $sha]);
        MapVersion::create(['map_id' => $map->id, 'sha256' => $sha, 'first_seen_at' => now(), 'last_seen_at' => now()]);
        Storage::disk('local')->put('maps/cachedmap_' . $sha . '.map', 'BYTES');

        $this->get('/map/cachedmap/')->assertOk()->assertSee('Cached')->assertDontSee('Downloadable');
    }

    public function test_detail_page_shows_an_unavailable_badge_for_a_keyless_hostless_map(): void
    {
        Storage::fake('local');

        Map::create(['name' => 'ghostmap']); // no identity, no host

        $this->get('/map/ghostmap/')->assertOk()->assertSee('Unavailable');
    }
}
