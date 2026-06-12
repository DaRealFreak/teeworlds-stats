<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Service\DdnetHttpMapSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
}
