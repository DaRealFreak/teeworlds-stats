<?php

namespace Tests\Feature;

use App\Models\Map;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapFileAcquisitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_map_stores_identity_columns_and_reports_downloadable(): void
    {
        $sha = str_repeat('a', 64);
        $map = Map::create(['name' => 'Kobra 4', 'sha256' => $sha, 'size' => 12345]);

        $fresh = $map->fresh();
        $this->assertSame($sha, $fresh->sha256);
        $this->assertSame(12345, $fresh->size);
        $this->assertNull($fresh->crc);
        $this->assertTrue($fresh->hasDownloadableFile());

        $bare = Map::create(['name' => 'no-hash']);
        $this->assertFalse($bare->fresh()->hasDownloadableFile());
    }
}
