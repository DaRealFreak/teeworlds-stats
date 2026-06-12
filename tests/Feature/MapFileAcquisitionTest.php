<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Service\DdnetHttpMapSource;
use App\Service\MapFileStore;
use App\Service\MapFileUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

    public function test_http_source_downloads_and_verifies_a_ddnet_map(): void
    {
        $body = 'DATAFILEMAPBYTES';
        $sha = hash('sha256', $body);
        $map = Map::create(['name' => 'Kobra 4', 'sha256' => $sha, 'size' => strlen($body)]);

        Http::fake(['maps.ddnet.org/*' => Http::response($body, 200)]);

        $bytes = (new DdnetHttpMapSource())->fetch($map);

        $this->assertSame($body, $bytes);
        Http::assertSent(fn ($req) => $req->url() === 'https://maps.ddnet.org/Kobra%204_' . $sha . '.map');
    }

    public function test_http_source_returns_null_on_hash_or_size_mismatch_or_no_sha(): void
    {
        Http::fake(['maps.ddnet.org/*' => Http::response('WRONGBYTES', 200)]);

        $bad = Map::create(['name' => 'x', 'sha256' => str_repeat('c', 64), 'size' => 10]);
        $this->assertNull((new DdnetHttpMapSource())->fetch($bad)); // sha mismatch

        $noSha = Map::create(['name' => 'y']);
        $this->assertNull((new DdnetHttpMapSource())->fetch($noSha)); // not eligible

        // size guard in isolation: sha256 matches the body, but the advertised size is wrong
        $sizeWrong = Map::create(['name' => 'z', 'sha256' => hash('sha256', 'WRONGBYTES'), 'size' => 999]);
        $this->assertNull((new DdnetHttpMapSource())->fetch($sizeWrong)); // rejected on size, not sha
    }

    public function test_store_downloads_once_then_serves_from_cache(): void
    {
        Storage::fake('local');
        $body = 'CACHEDBYTES';
        $sha = hash('sha256', $body);
        $map = Map::create(['name' => 'Kobra 4', 'sha256' => $sha, 'size' => strlen($body)]);
        Http::fake(['maps.ddnet.org/*' => Http::response($body, 200)]);

        $store = new MapFileStore();

        $rel = $store->ensure($map);
        $this->assertSame('maps/' . $sha . '.map', $rel);
        Storage::disk('local')->assertExists($rel);
        $this->assertSame($body, Storage::disk('local')->get($rel));

        $store->ensure($map); // second call must hit cache, not the network
        Http::assertSentCount(1);
    }

    public function test_store_throws_when_no_source_can_serve(): void
    {
        Storage::fake('local');
        $map = Map::create(['name' => 'no-hash']); // no sha256 → no eligible source

        $this->expectException(MapFileUnavailable::class);
        (new MapFileStore())->ensure($map);
    }

    public function test_store_throws_when_all_sources_return_null(): void
    {
        Storage::fake('local');
        $map = Map::create(['name' => 'ghost-map', 'sha256' => str_repeat('b', 64)]);

        // a source that is eligible (map has a key) but can never serve this map
        $nullSource = new class implements \App\Service\MapSource {
            public function fetch(\App\Models\Map $map): ?string
            {
                return null;
            }
        };

        $this->expectException(MapFileUnavailable::class);
        (new MapFileStore([$nullSource]))->ensure($map);
    }

    public function test_store_returns_cached_path_without_calling_any_source(): void
    {
        Storage::fake('local');
        $sha = str_repeat('c', 64);
        $map = Map::create(['name' => 'cached-map', 'sha256' => $sha]);
        Storage::disk('local')->put('maps/' . $sha . '.map', 'PRELOADED');

        // empty sources array — if the cache-exists branch did not short-circuit, this would throw
        $rel = (new MapFileStore([]))->ensure($map);

        $this->assertSame('maps/' . $sha . '.map', $rel);
        $this->assertSame('PRELOADED', Storage::disk('local')->get($rel));
    }

    public function test_download_route_streams_attachment_and_caches(): void
    {
        Storage::fake('local');
        $body = 'ROUTEBYTES';
        $sha = hash('sha256', $body);
        Map::create(['name' => 'Kobra 4', 'sha256' => $sha, 'size' => strlen($body)]);
        Http::fake(['maps.ddnet.org/*' => Http::response($body, 200)]);

        $response = $this->get('/map/' . rawurlencode('Kobra 4') . '/download');

        $response->assertOk();
        $response->assertDownload('Kobra 4.map'); // Symfony quotes the spaced filename; match via the helper
        $this->assertSame($body, $response->streamedContent());

        $fileResponse = $this->get('/map/' . rawurlencode('Kobra 4') . '/file'); // inline variant
        $fileResponse->assertOk();
        // robust partial match — Symfony emits `inline; filename="Kobra 4.map"; filename*=...` for a spaced name
        $this->assertStringStartsWith('inline', (string) $fileResponse->headers->get('content-disposition'));
        Http::assertSentCount(1); // both requests served one cached file
    }

    public function test_file_route_404s_for_unavailable_and_unknown_maps(): void
    {
        Storage::fake('local');
        Map::create(['name' => 'no-hash']); // exists but not downloadable

        $this->get('/map/no-hash/file')->assertNotFound();
        $this->get('/map/does-not-exist/file')->assertNotFound();
    }
}
