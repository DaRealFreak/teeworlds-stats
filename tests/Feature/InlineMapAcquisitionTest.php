<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapVersion;
use App\Service\InlineMapAcquirer;
use App\Service\MapFileStore;
use App\Service\MapSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InlineMapAcquisitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** The fixed bytes the fake source serves; a valid-looking datafile so verification passes. */
    private function fakeBytes(): string
    {
        return 'DATA' . str_repeat("\x00", 64);
    }

    private function bytesSource(): MapSource
    {
        $bytes = $this->fakeBytes();

        return new class($bytes) implements MapSource {
            public function __construct(private readonly string $bytes) {}
            public function fetch(Map $map): ?string { return null; }
            public function fetchVersion(MapVersion $version): ?string { return $this->bytes; }
        };
    }

    private function udpVersion(string $name = 'Aim', string $crc = 'deadbeef'): MapVersion
    {
        $map = Map::create(['name' => $name]);
        return MapVersion::create(['map_id' => $map->id, 'crc' => $crc, 'first_seen_at' => now(), 'last_seen_at' => now()]);
    }

    public function test_acquires_a_udp_only_uncached_version(): void
    {
        $store = new MapFileStore(sources: [$this->bytesSource()]);
        $version = $this->udpVersion();

        $acquirer = new InlineMapAcquirer($store);
        $acquirer->consider($version);
        $acquirer->run();

        $this->assertNotNull($version->fresh()->sha256, 'acquisition backfills the computed sha256');
        $this->assertTrue($store->isCachedVersion($version->fresh()));
        $this->assertNotNull($version->fresh()->last_acquire_attempt_at);
    }

    public function test_archives_http_acquirable_versions_too(): void
    {
        // A server-reported sha256 does not prove the map is mirrored on maps.ddnet.org (custom maps on
        // DDNet-flavoured servers carry a hash yet 404), so versions with a sha256 are archived inline
        // too — not left for on-demand. The version's sha256 matches the fake bytes, mirroring production.
        $store = new MapFileStore(sources: [$this->bytesSource()]);
        $map = Map::create(['name' => 'Aim']);
        $httpVersion = MapVersion::create([
            'map_id' => $map->id,
            'sha256' => hash('sha256', $this->fakeBytes()),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $acquirer = new InlineMapAcquirer($store);
        $acquirer->consider($httpVersion);
        $acquirer->run();

        $this->assertTrue($store->isCachedVersion($httpVersion->fresh()));
        $this->assertNotNull($httpVersion->fresh()->last_acquire_attempt_at);
    }

    public function test_skips_versions_in_cooldown(): void
    {
        $store = new MapFileStore(sources: [$this->bytesSource()]);
        $version = $this->udpVersion();
        $version->update(['last_acquire_attempt_at' => now()->subMinutes(5)]); // within default 6h cooldown

        $acquirer = new InlineMapAcquirer($store);
        $acquirer->consider($version);
        $acquirer->run();

        $this->assertFalse($store->isCachedVersion($version->fresh()));
    }

    public function test_honors_the_per_run_cap(): void
    {
        putenv('MAP_ACQUIRE_MAX_PER_RUN=1');
        try {
            $store = new MapFileStore(sources: [$this->bytesSource()]);
            $v1 = $this->udpVersion('A', 'aaaaaaaa');
            $v2 = $this->udpVersion('B', 'bbbbbbbb');

            $acquirer = new InlineMapAcquirer($store);
            $acquirer->consider($v1);
            $acquirer->consider($v2);
            $acquirer->run();

            $cached = (int) $store->isCachedVersion($v1->fresh()) + (int) $store->isCachedVersion($v2->fresh());
            $this->assertSame(1, $cached, 'only one version acquired under the cap');
        } finally {
            putenv('MAP_ACQUIRE_MAX_PER_RUN');
        }
    }
}
