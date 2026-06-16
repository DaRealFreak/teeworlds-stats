<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapVersion;
use App\Models\Mod;
use App\Models\Server;
use App\Models\ServerHistory;
use App\Service\DdnetHttpMapSource;
use App\Service\GameServerMapSource;
use App\Service\MapArchiveFiller;
use App\Service\MapFileStore;
use App\TwStats\Connection\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The unbounded, status-aware filler: drains the whole uncached backlog within a wall-clock budget,
 * newest-first, choosing the UDP-capable store only for versions a healthy live host is serving right
 * now (resolved in one bulk query per run).
 */
class MapArchiveFillTest extends TestCase
{
    use RefreshDatabase;

    /** Bytes whose sha256/size we pin onto each HTTP-cacheable version so MapFileStore verification passes. */
    private string $bytes;

    private string $sha;

    private int $size;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->bytes = 'DATA' . str_repeat("\0", 60);
        $this->sha = hash('sha256', $this->bytes);
        $this->size = strlen($this->bytes);
    }

    /** An HTTP-mirrored version: sha256 + size match the faked maps.ddnet.org body, so it verifies + caches. */
    private function httpVersion(string $name): MapVersion
    {
        $map = Map::create(['name' => $name]);

        return MapVersion::create([
            'map_id' => $map->id,
            'sha256' => $this->sha,
            'size' => $this->size,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    public function test_drains_more_than_the_old_25_cap_in_one_run(): void
    {
        Http::fake(['maps.ddnet.org/*' => Http::response($this->bytes, 200)]);

        for ($i = 0; $i < 30; $i++) {
            $this->httpVersion("Map{$i}");
        }

        $result = (new MapArchiveFiller())->run(limit: 0, timeBudgetS: 3600);

        $this->assertGreaterThanOrEqual(30, $result['attempted'], 'no 25-cap: every uncached version is attempted');
        foreach (MapVersion::all() as $version) {
            $this->assertSame(MapVersion::STATUS_CACHED, $version->fresh()->acquisition_status);
        }
    }

    public function test_respects_the_time_budget(): void
    {
        Http::fake(['maps.ddnet.org/*' => Http::response($this->bytes, 200)]);
        $this->httpVersion('A');
        $this->httpVersion('B');

        // A zero-second budget trips the loop guard before the first candidate is touched.
        $result = (new MapArchiveFiller())->run(limit: 0, timeBudgetS: 0);

        $this->assertSame(0, $result['attempted']);
    }

    public function test_orders_newest_first(): void
    {
        Http::fake(['maps.ddnet.org/*' => Http::response($this->bytes, 200)]);

        $newest = $this->httpVersion('Newest');
        $mid = $this->httpVersion('Mid');
        $oldest = $this->httpVersion('Oldest');
        // Distinct last_seen_at so ordering is deterministic (the recency signal the filler sorts on).
        DB::table('map_versions')->where('id', $newest->id)->update(['last_seen_at' => now()]);
        DB::table('map_versions')->where('id', $mid->id)->update(['last_seen_at' => now()->subHour()]);
        DB::table('map_versions')->where('id', $oldest->id)->update(['last_seen_at' => now()->subHours(2)]);

        (new MapArchiveFiller())->run(limit: 1, timeBudgetS: 3600);

        $this->assertSame(MapVersion::STATUS_CACHED, $newest->fresh()->acquisition_status);
        $this->assertNotSame(MapVersion::STATUS_CACHED, $mid->fresh()->acquisition_status);
        $this->assertNotSame(MapVersion::STATUS_CACHED, $oldest->fresh()->acquisition_status);
    }

    public function test_skips_cached_versions(): void
    {
        // A candidate whose file landed on disk since we listed candidates (a concurrent run / the inline
        // path). Its status is still non-cached so it survives the needingAcquisition() filter, but the
        // on-disk file means the filler must count it as skippedCached, not re-attempt the download. (A
        // version already status=cached is excluded from candidates entirely by needingAcquisition().)
        $version = $this->httpVersion('AlreadyOnDisk');
        Storage::disk('local')->put(
            (new MapFileStore())->relativePathForVersion($version),
            $this->bytes,
        );

        $result = (new MapArchiveFiller())->run(limit: 0, timeBudgetS: 3600);

        $this->assertSame(1, $result['skippedCached']);
        $this->assertSame(0, $result['attempted']);
    }

    public function test_no_udp_for_a_version_without_a_live_host(): void
    {
        // A crc-only version (no HTTP mirror) with NO server_histories row: no live host, so the filler must
        // route it to the HTTP store and never construct a UDP connection. We inject a fullStore whose
        // GameServerMapSource has a recording connection factory and assert it stays at zero invocations.
        $map = Map::create(['name' => 'Orphan']);
        MapVersion::create([
            'map_id' => $map->id,
            'crc' => 'deadbeef',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $dialed = 0;
        $fullStore = new MapFileStore(sources: [
            new DdnetHttpMapSource(),
            new GameServerMapSource(connectionFactory: function () use (&$dialed): ConnectionInterface {
                $dialed++;
                throw new \RuntimeException('a version without a live host must never dial UDP');
            }),
        ]);

        Http::fake(['maps.ddnet.org/*' => Http::response('nope', 404)]);

        (new MapArchiveFiller(fullStore: $fullStore))->run(limit: 0, timeBudgetS: 3600);

        $this->assertSame(0, $dialed, 'a version with no live host is never dialed over UDP');
    }
}
