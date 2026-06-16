<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MapVersionsPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_shows_per_version_file_availability(): void
    {
        Storage::fake('local');

        $map = Map::create(['name' => 'mix', 'crc' => '22222222']);
        // an uncached version, still pending acquisition → Queued
        MapVersion::create(['map_id' => $map->id, 'crc' => '11111111', 'first_seen_at' => now()->subDay(), 'last_seen_at' => now()->subDay()]);
        // a cached version → Cached (its .map file is on disk under the name_<crc> key)
        MapVersion::create(['map_id' => $map->id, 'crc' => '22222222', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        Storage::disk('local')->put('maps/mix_22222222.map', 'BYTES');

        $this->get('/map/mix/')
            ->assertOk()
            ->assertSeeInOrder(['22222222', 'Cached'])  // current (cached) row
            ->assertSeeInOrder(['11111111', 'Queued']); // older (uncached, pending) row
    }

    public function test_panel_lists_versions_when_there_are_multiple(): void
    {
        $map = Map::create(['name' => 'multi', 'crc' => '22222222']);
        MapVersion::create(['map_id' => $map->id, 'crc' => '11111111', 'size' => 100000, 'first_seen_at' => now()->subDay(), 'last_seen_at' => now()->subDay()]);
        MapVersion::create(['map_id' => $map->id, 'crc' => '22222222', 'size' => 200000, 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->get('/map/multi/')
            ->assertOk()
            ->assertSee('id="mapVersions"', false)
            ->assertSee('Versions (2)')
            ->assertSee('11111111')
            ->assertSee('22222222')
            ->assertSee('/map/multi/download/11111111');
    }

    public function test_panel_shows_even_for_a_single_version_map(): void
    {
        $map = Map::create(['name' => 'solo', 'crc' => '33333333']);
        MapVersion::create(['map_id' => $map->id, 'crc' => '33333333', 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->get('/map/solo/')
            ->assertOk()
            ->assertSee('id="mapVersions"', false)
            ->assertSee('Versions (1)')
            ->assertSee('33333333');
    }

    public function test_selecting_a_version_points_the_viewer_at_its_file(): void
    {
        $map = Map::create(['name' => 'multi', 'crc' => '22222222']);
        MapVersion::create(['map_id' => $map->id, 'crc' => '11111111', 'first_seen_at' => now()->subDay(), 'last_seen_at' => now()->subDay()]);
        MapVersion::create(['map_id' => $map->id, 'crc' => '22222222', 'first_seen_at' => now(), 'last_seen_at' => now()]);

        // default → current (newest, 22222222)
        $this->get('/map/multi/')->assertOk()->assertSee('/map/multi/file/22222222');
        // explicit selection → that version's file URL in the viewer script
        $this->get('/map/multi/?version=11111111')->assertOk()->assertSee('/map/multi/file/11111111');
    }
}
