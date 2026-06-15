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

    public function test_backfills_crc_onto_a_sha256_only_row(): void
    {
        $sha = str_repeat('e', 64);
        $row = $this->recorder->record($this->map, $sha, null, 300);
        $same = $this->recorder->record($this->map, $sha, 'feed1234', 300);

        $this->assertTrue($same->is($row));
        $this->assertSame('feed1234', $same->fresh()->crc);
        $this->assertDatabaseCount('map_versions', 1);
    }

    public function test_distinct_crcs_are_distinct_versions(): void
    {
        $this->recorder->record($this->map, null, '3421b577', 335351);
        $this->recorder->record($this->map, null, 'd6909b17', 281696);

        $this->assertDatabaseCount('map_versions', 2);
    }

    public function test_merges_two_rows_when_a_sighting_carries_both_keys(): void
    {
        $sha = str_repeat('c', 64);
        $shaRow = $this->recorder->record($this->map, $sha, null, 400);
        $crcRow = $this->recorder->record($this->map, null, 'cafef00d', 400);
        $crcRow->update(['first_seen_at' => now()->subDays(3)]);

        $merged = $this->recorder->record($this->map, $sha, 'cafef00d', 400);
        $this->assertTrue($merged->is($shaRow), 'the sha-row survives the merge');

        $this->assertDatabaseCount('map_versions', 1);
        $this->assertSame($sha, $merged->fresh()->sha256);
        $this->assertSame('cafef00d', $merged->fresh()->crc);
        $this->assertTrue($merged->fresh()->first_seen_at->equalTo(now()->subDays(3)));
    }

    public function test_merge_repoints_server_histories(): void
    {
        $sha = str_repeat('d', 64);
        $shaRow = $this->recorder->record($this->map, $sha, null, 500);
        $crcRow = $this->recorder->record($this->map, null, 'beadfeed', 500);

        $server = \App\Models\Server::factory()->create();
        $mod = \App\Models\Mod::create(['name' => 'x']);
        $make = fn (int $versionId) => \App\Models\ServerHistory::create([
            'weekday' => 0, 'hour' => 0, 'continuous' => 0,
            'server_id' => $server->id, 'map_id' => $this->map->id,
            'map_version_id' => $versionId, 'mod_id' => $mod->id,
        ]);
        $loserHistory = $make($crcRow->id);
        $winnerHistory = $make($shaRow->id);

        $merged = $this->recorder->record($this->map, $sha, 'beadfeed', 500);

        $this->assertSame($merged->id, $loserHistory->fresh()->map_version_id, 'loser history re-pointed');
        $this->assertSame($merged->id, $winnerHistory->fresh()->map_version_id, 'winner history unchanged');
        $this->assertDatabaseMissing('server_histories', ['map_version_id' => $crcRow->id]);
    }
}
