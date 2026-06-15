<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapVersion;
use App\Models\Mod;
use App\Models\Server;
use App\Models\ServerAddress;
use App\Models\ServerHistory;
use App\Service\DdnetHttpMapSource;
use App\Service\GameServerMapSource;
use App\Service\MapFileStore;
use App\Service\MapFileUnavailable;
use App\TwStats\Connection\ConnectionInterface;
use App\TwStats\Connection\MapDownloadFailed;
use App\TwStats\Protocol\Seven\VariableInt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $this->assertSame('maps/Kobra_4_' . $sha . '.map', $rel);
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
            public function fetchVersion(\App\Models\MapVersion $version): ?string
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
        Storage::disk('local')->put('maps/cached-map_' . $sha . '.map', 'PRELOADED');

        // empty sources array — if the cache-exists branch did not short-circuit, this would throw
        $rel = (new MapFileStore([]))->ensure($map);

        $this->assertSame('maps/cached-map_' . $sha . '.map', $rel);
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

    public function test_map_page_shows_download_button_only_when_downloadable(): void
    {
        Map::create(['name' => 'Kobra 4', 'sha256' => str_repeat('a', 64), 'size' => 10]);
        Map::create(['name' => 'no-hash']);

        $this->get('/map/' . rawurlencode('Kobra 4') . '/')
            ->assertOk()
            ->assertSee('Download .map');

        $this->get('/map/no-hash/')
            ->assertOk()
            ->assertDontSee('Download .map')
            ->assertSee('Map file not available');
    }

    public function test_map_page_shows_the_viewer_canvas_only_when_downloadable(): void
    {
        \App\Models\Map::create(['name' => 'Kobra 4', 'sha256' => str_repeat('a', 64), 'size' => 10]);
        \App\Models\Map::create(['name' => 'no-hash']);

        $this->get('/map/' . rawurlencode('Kobra 4') . '/')->assertOk()->assertSee('id="mapCanvas"', false);
        $this->get('/map/no-hash/')->assertOk()->assertDontSee('id="mapCanvas"', false);
    }

    // -----------------------------------------------------------------------
    // GameServerMapSource + MapFileStore wiring tests (Phase E, Task 7)
    // -----------------------------------------------------------------------

    /**
     * Build a NETMSG_MAP_CHANGE + NETMSG_MAP_DATA sequence for the FakeGameConnection.
     * The FakeGameConnection below lives at the bottom of this file as an inner class.
     */
    private function buildMapChange(string $name, int $crc, int $size): string
    {
        $crc = $this->toInt32($crc);
        return VariableInt::pack((2 << 1) | 1) // NETMSG_MAP_CHANGE = 2
            . $name . "\x00"
            . VariableInt::pack($crc)
            . VariableInt::pack($size);
    }

    private function buildMapData(int $last, int $crc, int $chunk, string $data): string
    {
        $crc  = $this->toInt32($crc);
        $size = strlen($data);
        return VariableInt::pack((3 << 1) | 1) // NETMSG_MAP_DATA = 3
            . VariableInt::pack($last)
            . VariableInt::pack($crc)
            . VariableInt::pack($chunk)
            . VariableInt::pack($size)
            . $data;
    }

    private function toInt32(int $v): int
    {
        $v &= 0xFFFFFFFF;
        if ($v & 0x80000000) {
            $v -= 0x100000000;
        }
        return $v;
    }

    /**
     * Seed a Map + Server + ServerAddress + ServerHistory for UDP download tests.
     *
     * @return array{Map, Server, ServerAddress}
     */
    private function seedHostedMap(string $mapName, string $crcHex): array
    {
        $map    = Map::create(['name' => $mapName, 'crc' => $crcHex]);
        $mod    = Mod::create(['name' => 'dm']);
        $server = Server::factory()->create(['version' => '0.6.4', 'flavor' => 'vanilla_06']);
        $addr   = ServerAddress::factory()->create([
            'server_id'    => $server->id,
            'ip'           => '1.2.3.4',
            'port'         => 8303,
            'protocol'     => 6,
            'is_canonical' => true,
        ]);

        ServerHistory::create([
            'weekday'    => 1,
            'hour'       => 12,
            'continuous' => true,
            'server_id'  => $server->id,
            'map_id'     => $map->id,
            'mod_id'     => $mod->id,
            'minutes'    => 10,
        ]);

        return [$map, $server, $addr];
    }

    /**
     * UDP download success via fake factory.
     * Seeds a crc-only map with a server_history → fake connection scripts MAP_CHANGE + MAP_DATA
     * → fetch() returns the bytes; MapFileStore caches at maps/<name>_<crc>.map.
     */
    public function test_udp_download_success_via_fake_factory(): void
    {
        Storage::fake('local');

        $payload = "DATA" . str_repeat('M', 40); // valid datafile: 'DATA' magic + ≥36 bytes
        $crc     = crc32($payload);
        $crcHex  = sprintf('%08x', $crc & 0xFFFFFFFF);

        [$map] = $this->seedHostedMap('dm1', $crcHex);

        $conn = new FakeGameConnection();
        $conn->enqueue([$this->buildMapChange('dm1', $crc, strlen($payload))]);
        $conn->enqueue([$this->buildMapData(1, $crc, 0, $payload)]);

        $source = new GameServerMapSource(
            connectionFactory: static fn () => $conn,
        );

        $bytes = $source->fetch($map);
        $this->assertSame($payload, $bytes);

        // Also verify the store caches it under maps/<name>_<crc>.map.
        $store = new MapFileStore([$source]);
        // File was fetched once already; we need a fresh map model and a new conn for the store path.
        $conn2 = new FakeGameConnection();
        $conn2->enqueue([$this->buildMapChange('dm1', $crc, strlen($payload))]);
        $conn2->enqueue([$this->buildMapData(1, $crc, 0, $payload)]);

        $source2 = new GameServerMapSource(
            connectionFactory: static fn () => $conn2,
        );
        $store2  = new MapFileStore([$source2]);
        $rel     = $store2->ensure($map);
        $this->assertSame('maps/dm1_' . $crcHex . '.map', $rel);
        Storage::disk('local')->assertExists($rel);
        $this->assertSame($payload, Storage::disk('local')->get($rel));
    }

    /**
     * No server_history for the map → fetch() returns null (no exception).
     */
    public function test_udp_no_host_returns_null(): void
    {
        $map = Map::create(['name' => 'orphan', 'crc' => 'deadbeef']);

        $source = new GameServerMapSource(
            connectionFactory: static fn () => new FakeGameConnection(),
        );

        $this->assertNull($source->fetch($map));
    }

    /**
     * Fake connection throws MapDownloadFailed → fetch() returns null (no exception propagates).
     */
    public function test_udp_failure_returns_null(): void
    {
        $payload = 'TESTPAYLOAD';
        $crc     = crc32($payload);
        $crcHex  = sprintf('%08x', $crc & 0xFFFFFFFF);

        [$map] = $this->seedHostedMap('dm2', $crcHex);

        // A connection whose download() throws MapDownloadFailed.
        $throwingConn = new class implements ConnectionInterface {
            public function sendVital(string $message): void {}
            public function flush(): void {}
            public function receive(int $timeoutMs): array
            {
                // Return empty batches forever → MapDownloadSession times out.
                return [];
            }
            public function disconnect(): void {}
            public function isOnline(): bool { return true; }
        };

        $source = new GameServerMapSource(
            connectionFactory: static fn () => $throwingConn,
            timeoutMs: 1, // 1 ms → immediate timeout inside MapDownloadSession
        );

        $this->assertNull($source->fetch($map));
    }

    /**
     * hasDownloadableFile returns true for a crc-only map and false for a bare map.
     */
    public function test_has_downloadable_file_broadened_to_crc(): void
    {
        $crcMap  = Map::create(['name' => 'crc-only', 'crc' => 'aabbccdd']);
        $bareMap = Map::create(['name' => 'bare']);

        $this->assertTrue($crcMap->fresh()->hasDownloadableFile());
        $this->assertFalse($bareMap->fresh()->hasDownloadableFile());
    }

    /**
     * MapFileStore::ensure throws MapFileUnavailable (not a filesystem path) when
     * the map's key (sha256 or crc) contains non-hex characters — path-traversal guard.
     */
    public function test_hex_guardrail_throws_on_non_hex_key(): void
    {
        Storage::fake('local');

        // Craft a map whose crc is not valid hex — simulates a corrupted/tampered record.
        $map = Map::create(['name' => 'evil', 'crc' => '../etc']);

        $this->expectException(MapFileUnavailable::class);
        (new MapFileStore([]))->ensure($map);

        // Verify no file was written.
        Storage::disk('local')->assertMissing('maps/../etc.map');
    }

    public function test_udp_downloads_a_keyless_map_when_name_matches(): void
    {
        $payload = "DATA" . str_repeat('K', 40);
        $crc     = crc32($payload);

        // Seeded inline rather than via seedHostedMap(), which sets a crc and would make the map
        // no longer keyless — the whole point of this test is a map with neither sha256 nor crc.
        $map    = Map::create(['name' => 'kobra']);
        $mod    = Mod::create(['name' => 'dm']);
        $server = Server::factory()->create(['version' => '0.6.4', 'flavor' => 'vanilla_06']);
        ServerAddress::factory()->create([
            'server_id' => $server->id, 'ip' => '1.2.3.4', 'port' => 8303,
            'protocol' => 6, 'is_canonical' => true,
        ]);
        ServerHistory::create([
            'weekday' => 1, 'hour' => 12, 'continuous' => true,
            'server_id' => $server->id, 'map_id' => $map->id, 'mod_id' => $mod->id, 'minutes' => 10,
        ]);

        $conn = new FakeGameConnection();
        $conn->enqueue([$this->buildMapChange('kobra', $crc, strlen($payload))]);
        $conn->enqueue([$this->buildMapData(1, $crc, 0, $payload)]);

        $source = new GameServerMapSource(connectionFactory: static fn () => $conn);

        $this->assertSame($payload, $source->fetch($map));
    }

    public function test_udp_rejects_when_server_reports_a_different_map(): void
    {
        $payload = "DATA" . str_repeat('X', 40);
        $crc     = crc32($payload);

        [$map] = $this->seedHostedMap('dm1', sprintf('%08x', $crc & 0xFFFFFFFF));

        // host has since swapped to 'ctf7' — name must not match 'dm1'
        $conn = new FakeGameConnection();
        $conn->enqueue([$this->buildMapChange('ctf7', $crc, strlen($payload))]);
        $conn->enqueue([$this->buildMapData(1, $crc, 0, $payload)]);

        $source = new GameServerMapSource(connectionFactory: static fn () => $conn);

        $this->assertNull($source->fetch($map));
    }

    public function test_udp_rejects_non_datafile_bytes(): void
    {
        $payload = 'NOTADATAFILE-juststuff-0123456789012345'; // ≥36 bytes but no DATA/ATAD magic
        $crc     = crc32($payload);

        [$map] = $this->seedHostedMap('dm1', sprintf('%08x', $crc & 0xFFFFFFFF));

        $conn = new FakeGameConnection();
        $conn->enqueue([$this->buildMapChange('dm1', $crc, strlen($payload))]);
        $conn->enqueue([$this->buildMapData(1, $crc, 0, $payload)]);

        $source = new GameServerMapSource(connectionFactory: static fn () => $conn);

        $this->assertNull($source->fetch($map));
    }

    public function test_store_backfills_identity_and_caches_a_keyless_map(): void
    {
        Storage::fake('local');

        $body = "DATA" . str_repeat('Z', 40);
        $sha  = hash('sha256', $body);
        $map  = Map::create(['name' => 'keyless map']); // no sha256, no crc

        $source = new class($body) implements \App\Service\MapSource {
            public function __construct(private string $body) {}
            public function fetch(\App\Models\Map $map): ?string { return $this->body; }
            public function fetchVersion(\App\Models\MapVersion $version): ?string { return $this->body; }
        };

        $rel = (new MapFileStore([$source]))->ensure($map);

        $this->assertSame('maps/keyless_map_' . $sha . '.map', $rel);
        Storage::disk('local')->assertExists($rel);

        $fresh = $map->fresh();
        $this->assertSame($sha, $fresh->sha256);
        $this->assertSame(sprintf('%08x', crc32($body) & 0xFFFFFFFF), $fresh->crc);
        $this->assertSame(strlen($body), $fresh->size);
    }

    public function test_store_backfill_does_not_clobber_an_existing_size(): void
    {
        Storage::fake('local');

        $body = "DATA" . str_repeat('Q', 40);
        $map  = Map::create(['name' => 'sized', 'size' => 99999]); // size preset, still keyless

        $source = new class($body) implements \App\Service\MapSource {
            public function __construct(private string $body) {}
            public function fetch(\App\Models\Map $map): ?string { return $this->body; }
            public function fetchVersion(\App\Models\MapVersion $version): ?string { return $this->body; }
        };

        (new MapFileStore([$source]))->ensure($map);

        $this->assertSame(99999, $map->fresh()->size, 'a preset size must not be overwritten');
    }

    public function test_download_from_host_returns_verified_bytes(): void
    {
        $payload = 'DATA' . str_repeat('H', 40);
        $crc = crc32($payload);
        $conn = new FakeGameConnection();
        $conn->enqueue([$this->buildMapChange('dm1', $crc, strlen($payload))]);
        $conn->enqueue([$this->buildMapData(1, $crc, 0, $payload)]);

        $source = new GameServerMapSource(connectionFactory: static fn () => $conn);
        $crcSigned = ($crc & 0x80000000) ? $crc - 0x100000000 : $crc;

        $this->assertSame($payload, $source->downloadFromHost('1.2.3.4', 8303, 'vanilla_06', null, $crcSigned, 'dm1', 20000));
    }

    public function test_fetch_version_falls_through_to_a_second_host(): void
    {
        $payload = 'DATA' . str_repeat('V', 40);
        $crc = crc32($payload);
        $crcHex = sprintf('%08x', $crc & 0xFFFFFFFF);

        $map = Map::create(['name' => 'multi']);
        $version = MapVersion::create(['map_id' => $map->id, 'crc' => $crcHex, 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $mod = Mod::create(['name' => 'dm']);

        // hostA is most-recent but has switched maps; hostB still serves 'multi'
        $hostA = Server::factory()->create(['flavor' => 'vanilla_06']);
        ServerAddress::factory()->create(['server_id' => $hostA->id, 'ip' => '10.0.0.1', 'port' => 8303, 'protocol' => 6, 'is_canonical' => true]);
        $histA = ServerHistory::create(['weekday' => 1, 'hour' => 12, 'continuous' => true, 'server_id' => $hostA->id, 'map_id' => $map->id, 'map_version_id' => $version->id, 'mod_id' => $mod->id, 'minutes' => 10]);

        $hostB = Server::factory()->create(['flavor' => 'vanilla_06']);
        ServerAddress::factory()->create(['server_id' => $hostB->id, 'ip' => '10.0.0.2', 'port' => 8303, 'protocol' => 6, 'is_canonical' => true]);
        $histB = ServerHistory::create(['weekday' => 1, 'hour' => 12, 'continuous' => true, 'server_id' => $hostB->id, 'map_id' => $map->id, 'map_version_id' => $version->id, 'mod_id' => $mod->id, 'minutes' => 10]);

        // force deterministic ordering: hostA tried first (updated_at is guarded, so set via the query builder)
        DB::table('server_histories')->where('id', $histA->id)->update(['updated_at' => now()]);
        DB::table('server_histories')->where('id', $histB->id)->update(['updated_at' => now()->subHour()]);

        $connA = new FakeGameConnection();
        $connA->enqueue([$this->buildMapChange('OTHER', $crc, strlen($payload))]);
        $connA->enqueue([$this->buildMapData(1, $crc, 0, $payload)]);
        $connB = new FakeGameConnection();
        $connB->enqueue([$this->buildMapChange('multi', $crc, strlen($payload))]);
        $connB->enqueue([$this->buildMapData(1, $crc, 0, $payload)]);

        $byIp = ['10.0.0.1' => $connA, '10.0.0.2' => $connB];
        $source = new GameServerMapSource(connectionFactory: fn (string $ip, int $port, string $flavor) => $byIp[$ip]);

        $this->assertSame($payload, $source->fetchVersion($version->fresh()));
    }

    private function buildMapChange07(string $name, int $crc, int $size, int $chunksPerRequest, int $chunkSize, string $sha256Raw): string
    {
        return VariableInt::pack((2 << 1) | 1)
            . $name . "\x00"
            . VariableInt::pack($this->toInt32($crc))
            . VariableInt::pack($size)
            . VariableInt::pack($chunksPerRequest)
            . VariableInt::pack($chunkSize)
            . $sha256Raw;
    }

    private function buildMapData07(string $data): string
    {
        return VariableInt::pack((3 << 1) | 1) . $data;
    }

    public function test_udp_uses_the_07_session_for_a_sixup_host(): void
    {
        $payload   = "DATA" . str_repeat('S', 40);
        $crc       = crc32($payload);
        $sha256Raw = hex2bin(hash('sha256', $payload));

        // keyless map hosted on a vanilla_07 server reachable on a protocol-7 address
        $map    = Map::create(['name' => 'sixup-map']);
        $mod    = Mod::create(['name' => 'dm']);
        $server = Server::factory()->create(['version' => '0.7.5', 'flavor' => 'vanilla_07']);
        ServerAddress::factory()->create([
            'server_id' => $server->id, 'ip' => '9.9.9.9', 'port' => 8307,
            'protocol' => 7, 'is_canonical' => true,
        ]);
        ServerHistory::create([
            'weekday' => 1, 'hour' => 12, 'continuous' => true,
            'server_id' => $server->id, 'map_id' => $map->id, 'mod_id' => $mod->id, 'minutes' => 5,
        ]);

        $conn = new FakeGameConnection();
        $conn->enqueue([$this->buildMapChange07('sixup-map', $crc, strlen($payload), 4, 16, $sha256Raw)]);
        $conn->enqueue([$this->buildMapData07($payload)]);

        $captured = null;
        $source = new GameServerMapSource(
            connectionFactory: static function (string $ip, int $port, string $flavor) use ($conn, &$captured) {
                $captured = [$ip, $port, $flavor];
                return $conn;
            },
        );

        $this->assertSame($payload, $source->fetch($map));
        $this->assertSame(['9.9.9.9', 8307, 'vanilla_07'], $captured, 'must dial the protocol-7 address with the 0.7 flavor');
    }
}

// ---------------------------------------------------------------------------
// FakeGameConnection — scripted ConnectionInterface for GameServerMapSource tests
// ---------------------------------------------------------------------------

/**
 * Lightweight fake modelled after FakeConnection in MapDownloadSessionTest.
 * Batches are pre-enqueued; each receive() call pops one batch.
 */
final class FakeGameConnection implements ConnectionInterface
{
    /** @var array<int, string[]> */
    private array $inbox = [];

    /**
     * @param string[] $messages
     */
    public function enqueue(array $messages): void
    {
        $this->inbox[] = $messages;
    }

    public function sendVital(string $message): void {}

    public function flush(): void {}

    /** @return string[] */
    public function receive(int $timeoutMs): array
    {
        if ($this->inbox === []) {
            return [];
        }
        return array_shift($this->inbox);
    }

    public function disconnect(): void {}

    public function isOnline(): bool { return true; }
}
