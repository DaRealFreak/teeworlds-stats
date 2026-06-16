<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapVersion;
use App\Service\MapAvailability;
use App\Service\MapFileStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The availability badge is status-driven: an uncached version reflects its acquisition_status, while a
 * file on disk always wins (Cached) regardless of the persisted status.
 */
class MapAvailabilityStatusTest extends TestCase
{
    use RefreshDatabase;

    private function version(string $status): MapVersion
    {
        $map = Map::create(['name' => 'm-' . $status, 'crc' => 'deadbeef']);
        $v = MapVersion::create(['map_id' => $map->id, 'crc' => 'deadbeef', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $v->markStatus($status);

        return $v;
    }

    public function test_pending_version_is_queued(): void
    {
        Storage::fake('local');

        $this->assertSame(
            MapAvailability::Queued,
            MapAvailability::forVersion($this->version(MapVersion::STATUS_PENDING), new MapFileStore())
        );
    }

    public function test_udp_only_version_is_server_only(): void
    {
        Storage::fake('local');

        $this->assertSame(
            MapAvailability::ServerOnly,
            MapAvailability::forVersion($this->version(MapVersion::STATUS_UDP_ONLY), new MapFileStore())
        );
    }

    public function test_unavailable_version_is_unavailable(): void
    {
        Storage::fake('local');

        $this->assertSame(
            MapAvailability::Unavailable,
            MapAvailability::forVersion($this->version(MapVersion::STATUS_UNAVAILABLE), new MapFileStore())
        );
    }

    public function test_a_file_on_disk_is_cached_regardless_of_status(): void
    {
        Storage::fake('local');

        $store = new MapFileStore();
        // status says unavailable, but the file is on disk → disk wins.
        $version = $this->version(MapVersion::STATUS_UNAVAILABLE);
        Storage::disk('local')->put($store->relativePathForVersion($version), 'DATA' . str_repeat('M', 40));

        $this->assertSame(MapAvailability::Cached, MapAvailability::forVersion($version, $store));
    }
}
