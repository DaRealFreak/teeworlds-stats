<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapVersion;
use App\Service\DdnetHttpMapSource;
use App\Service\MapFileStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MapFileStoreVersionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_ensure_version_caches_an_http_map_under_the_version_key(): void
    {
        $bytes = 'DATA' . str_repeat("\x00", 64);
        $sha = hash('sha256', $bytes);
        $map = Map::create(['name' => 'Aim']);
        $version = MapVersion::create(['map_id' => $map->id, 'sha256' => $sha, 'size' => strlen($bytes), 'first_seen_at' => now(), 'last_seen_at' => now()]);

        Http::fake(['maps.ddnet.org/*' => Http::response($bytes, 200)]);
        $store = new MapFileStore(sources: [new DdnetHttpMapSource()]);

        $relative = $store->ensureVersion($version);

        $this->assertStringContainsString($sha, $relative);
        $this->assertTrue(Storage::disk('local')->exists($relative));
        $this->assertSame($bytes, Storage::disk('local')->get($relative));
        $this->assertTrue($store->isCachedVersion($version->fresh()));
    }

    public function test_ensure_version_returns_cached_without_refetching(): void
    {
        $bytes = 'DATA' . str_repeat("\x01", 64);
        $sha = hash('sha256', $bytes);
        $map = Map::create(['name' => 'Aim']);
        $version = MapVersion::create(['map_id' => $map->id, 'sha256' => $sha, 'size' => strlen($bytes), 'first_seen_at' => now(), 'last_seen_at' => now()]);

        Http::fake(['maps.ddnet.org/*' => Http::response($bytes, 200)]);
        $store = new MapFileStore(sources: [new DdnetHttpMapSource()]);
        $store->ensureVersion($version);

        Http::fake(['maps.ddnet.org/*' => Http::response('', 500)]);
        $this->assertNotEmpty($store->ensureVersion($version->fresh()));
    }

    public function test_ensure_version_throws_when_no_source_serves(): void
    {
        $map = Map::create(['name' => 'ghost']);
        $version = MapVersion::create(['map_id' => $map->id, 'crc' => 'deadbeef', 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->expectException(\App\Service\MapFileUnavailable::class);
        (new MapFileStore(sources: []))->ensureVersion($version);
    }
}
