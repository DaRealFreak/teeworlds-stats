<?php

namespace Tests\Unit;

use App\Models\Map;
use App\Models\MapVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapVersionRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_version_is_the_newest_by_last_seen(): void
    {
        $map = Map::create(['name' => 'FlipLipp30']);
        MapVersion::create(['map_id' => $map->id, 'crc' => 'aaaaaaaa', 'last_seen_at' => now()->subDay(), 'first_seen_at' => now()->subDay()]);
        $newest = MapVersion::create(['map_id' => $map->id, 'crc' => 'bbbbbbbb', 'last_seen_at' => now(), 'first_seen_at' => now()]);

        $this->assertCount(2, $map->versions);
        $this->assertTrue($map->currentVersion->is($newest));
    }

    public function test_content_key_prefers_sha256_then_crc(): void
    {
        $sha = str_repeat('a', 64);
        $this->assertSame($sha, (new MapVersion(['sha256' => $sha, 'crc' => 'deadbeef']))->contentKey());
        $this->assertSame('deadbeef', (new MapVersion(['crc' => 'deadbeef']))->contentKey());
        $this->assertNull((new MapVersion())->contentKey());
    }
}
