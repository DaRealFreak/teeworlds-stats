<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MapFileVersionRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_serves_a_specific_version_by_content_key(): void
    {
        $map = Map::create(['name' => 'multi']);
        MapVersion::create(['map_id' => $map->id, 'crc' => '11111111', 'first_seen_at' => now()->subDay(), 'last_seen_at' => now()->subDay()]);
        MapVersion::create(['map_id' => $map->id, 'crc' => '22222222', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        Storage::disk('local')->put('maps/multi_11111111.map', 'V1BYTES');
        Storage::disk('local')->put('maps/multi_22222222.map', 'V2BYTES');

        $this->assertSame('V1BYTES', $this->get('/map/multi/file/11111111')->assertOk()->streamedContent());
        $this->assertSame('V2BYTES', $this->get('/map/multi/file/22222222')->assertOk()->streamedContent());
    }

    public function test_default_serves_the_current_version(): void
    {
        $map = Map::create(['name' => 'multi']);
        MapVersion::create(['map_id' => $map->id, 'crc' => '11111111', 'first_seen_at' => now()->subDay(), 'last_seen_at' => now()->subDay()]);
        MapVersion::create(['map_id' => $map->id, 'crc' => '22222222', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        Storage::disk('local')->put('maps/multi_22222222.map', 'V2BYTES'); // newest = current

        $this->assertSame('V2BYTES', $this->get('/map/multi/file')->assertOk()->streamedContent());
    }

    public function test_unknown_version_key_404s(): void
    {
        $map = Map::create(['name' => 'multi']);
        MapVersion::create(['map_id' => $map->id, 'crc' => '11111111', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        Storage::disk('local')->put('maps/multi_11111111.map', 'V1BYTES');

        $this->get('/map/multi/file/deadbeef')->assertNotFound();
    }

    public function test_specific_version_download_is_an_attachment(): void
    {
        $map = Map::create(['name' => 'multi']);
        MapVersion::create(['map_id' => $map->id, 'sha256' => str_repeat('a', 64), 'first_seen_at' => now(), 'last_seen_at' => now()]);
        Storage::disk('local')->put('maps/multi_' . str_repeat('a', 64) . '.map', 'SHABYTES');

        $this->get('/map/multi/download/' . str_repeat('a', 64))
            ->assertOk()
            ->assertDownload('multi.map');
    }
}
