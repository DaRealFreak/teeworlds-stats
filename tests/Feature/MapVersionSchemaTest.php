<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapVersionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_map_versions_table_and_columns_exist(): void
    {
        $map = Map::create(['name' => 'FlipLipp30']);
        $version = MapVersion::create([
            'map_id' => $map->id,
            'crc' => '3421b577',
            'size' => 335351,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->assertDatabaseHas('map_versions', ['id' => $version->id, 'crc' => '3421b577']);
        $this->assertSame($map->id, $version->fresh()->map_id);
    }
}
