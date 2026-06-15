<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapVersionsPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_lists_versions_when_there_are_multiple(): void
    {
        $map = Map::create(['name' => 'multi']);
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

    public function test_no_panel_for_a_single_version_map(): void
    {
        $map = Map::create(['name' => 'solo']);
        MapVersion::create(['map_id' => $map->id, 'crc' => '33333333', 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->get('/map/solo/')->assertOk()->assertDontSee('id="mapVersions"', false);
    }

    public function test_selecting_a_version_points_the_viewer_at_its_file(): void
    {
        $map = Map::create(['name' => 'multi']);
        MapVersion::create(['map_id' => $map->id, 'crc' => '11111111', 'first_seen_at' => now()->subDay(), 'last_seen_at' => now()->subDay()]);
        MapVersion::create(['map_id' => $map->id, 'crc' => '22222222', 'first_seen_at' => now(), 'last_seen_at' => now()]);

        // default → current (newest, 22222222)
        $this->get('/map/multi/')->assertOk()->assertSee('/map/multi/file/22222222');
        // explicit selection → that version's file URL in the viewer script
        $this->get('/map/multi/?version=11111111')->assertOk()->assertSee('/map/multi/file/11111111');
    }
}
