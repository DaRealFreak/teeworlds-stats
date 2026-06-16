<?php

namespace Tests\Unit\Models;

use App\Models\Map;
use App\Models\MapVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapVersionStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_version_defaults_to_pending(): void
    {
        $map = Map::create(['name' => 'FlipLipp30']);
        $version = MapVersion::create(['map_id' => $map->id, 'sha256' => str_repeat('a', 64)]);

        $version->refresh();

        $this->assertSame(MapVersion::STATUS_PENDING, $version->acquisition_status);
    }

    public function test_mark_status_sets_status_and_checked_at_without_touching_updated_at(): void
    {
        $map = Map::create(['name' => 'Kobra4']);
        $version = MapVersion::create(['map_id' => $map->id, 'sha256' => str_repeat('b', 64)]);

        // Pin updated_at to a known past value via the base query so it carries no recency signal of its own.
        $past = now()->subDay();
        MapVersion::whereKey($version->id)->toBase()->update(['updated_at' => $past]);
        $version->refresh();

        $version->markStatus(MapVersion::STATUS_UDP_ONLY);

        // In-memory instance reflects the change immediately.
        $this->assertSame(MapVersion::STATUS_UDP_ONLY, $version->acquisition_status);

        $fresh = MapVersion::find($version->id);
        $this->assertSame(MapVersion::STATUS_UDP_ONLY, $fresh->acquisition_status);
        $this->assertNotNull($fresh->status_checked_at);
        // markStatus must NOT bump updated_at — it would corrupt the recency signal the recheck gate reads.
        $this->assertSame($past->toDateTimeString(), $fresh->updated_at->toDateTimeString());
    }

    public function test_scope_needing_acquisition_excludes_cached(): void
    {
        $map = Map::create(['name' => 'Multeasymap']);
        $pending = MapVersion::create(['map_id' => $map->id, 'sha256' => str_repeat('1', 64)]);
        $udpOnly = MapVersion::create(['map_id' => $map->id, 'sha256' => str_repeat('2', 64)]);
        $cached = MapVersion::create(['map_id' => $map->id, 'sha256' => str_repeat('3', 64)]);

        $udpOnly->markStatus(MapVersion::STATUS_UDP_ONLY);
        $cached->markStatus(MapVersion::STATUS_CACHED);

        $ids = MapVersion::needingAcquisition()->pluck('id');

        $this->assertContains($pending->id, $ids);
        $this->assertContains($udpOnly->id, $ids);
        $this->assertNotContains($cached->id, $ids);
    }
}
