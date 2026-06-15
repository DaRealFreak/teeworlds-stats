<?php

namespace Tests\Unit\Service;

use App\Models\Map;
use App\Models\MapVersion;
use App\Service\MapVersionRecorder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapVersionRecorderTest extends TestCase
{
    use RefreshDatabase;

    private MapVersionRecorder $recorder;
    private Map $map;

    protected function setUp(): void
    {
        parent::setUp();
        // Freeze time at second precision so that Carbon::equalTo() assertions hold across the
        // read-back path: SQLite timestamps have no sub-second resolution, so any fresh now() call
        // with microseconds would compare unequal to a value stored and retrieved from the DB.
        Carbon::setTestNow(Carbon::now()->startOfSecond());
        $this->recorder = new MapVersionRecorder();
        $this->map = Map::create(['name' => 'FlipLipp30']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    public function test_keyless_sighting_records_nothing(): void
    {
        $this->assertNull($this->recorder->record($this->map, null, null, null));
        $this->assertDatabaseCount('map_versions', 0);
    }

    public function test_creates_a_version_for_a_new_crc(): void
    {
        $v = $this->recorder->record($this->map, null, '3421b577', 335351);

        $this->assertSame('3421b577', $v->crc);
        $this->assertSame(335351, $v->size);
        $this->assertNull($v->sha256);
        $this->assertDatabaseCount('map_versions', 1);
    }

    public function test_re_sighting_the_same_crc_bumps_last_seen_without_creating_a_row(): void
    {
        $first = $this->recorder->record($this->map, null, '3421b577', 335351);
        $first->update(['last_seen_at' => now()->subHour()]);

        $again = $this->recorder->record($this->map, null, '3421b577', 335351);

        $this->assertTrue($again->is($first));
        $this->assertDatabaseCount('map_versions', 1);
        $this->assertTrue($again->last_seen_at->greaterThan(now()->subMinute()));
    }

    public function test_matches_by_sha256(): void
    {
        $sha = str_repeat('a', 64);
        $first = $this->recorder->record($this->map, $sha, null, 100);
        $again = $this->recorder->record($this->map, $sha, null, 100);

        $this->assertTrue($again->is($first));
        $this->assertDatabaseCount('map_versions', 1);
    }

    public function test_backfills_sha256_onto_a_crc_only_row(): void
    {
        $sha = str_repeat('b', 64);
        $row = $this->recorder->record($this->map, null, 'deadbeef', 200);
        $same = $this->recorder->record($this->map, $sha, 'deadbeef', 200);

        $this->assertTrue($same->is($row));
        $this->assertSame($sha, $same->fresh()->sha256);
        $this->assertDatabaseCount('map_versions', 1);
    }

    public function test_distinct_crcs_are_distinct_versions(): void
    {
        $this->recorder->record($this->map, null, '3421b577', 335351);
        $this->recorder->record($this->map, null, 'd6909b17', 281696);

        $this->assertDatabaseCount('map_versions', 2);
    }
}
