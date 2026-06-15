<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapAcquisitionAttempt;
use App\Models\MapVersion;
use App\Models\Server;
use App\Service\MapArchiveStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MapArchiveStatsTest extends TestCase
{
    use RefreshDatabase;

    private function attempt(array $attrs): void
    {
        $row = MapAcquisitionAttempt::create(array_merge([
            'source' => 'udp', 'outcome' => 'failure',
        ], $attrs));
        if (isset($attrs['created_at'])) {
            DB::table('map_acquisition_attempts')->where('id', $row->id)->update(['created_at' => $attrs['created_at']]);
        }
    }

    public function test_overview_counts_versions_and_cached_files(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('maps/A_aaaa.map', 'xx');
        Storage::disk('local')->put('maps/B_bbbb.map', 'yyyy');
        $map = Map::create(['name' => 'M']);
        MapVersion::create(['map_id' => $map->id, 'crc' => 'deadbeef']);
        MapVersion::create(['map_id' => $map->id, 'crc' => 'feedface', 'last_acquire_attempt_at' => now()]);

        $o = (new MapArchiveStats('local'))->overview();

        $this->assertSame(2, $o['cachedFiles']);
        $this->assertSame(6, $o['cachedBytes']);
        $this->assertSame(2, $o['totalVersions']);
        $this->assertSame(1, $o['neverAttempted']);
        $this->assertSame(1, $o['coolingDown']);
    }

    public function test_downloads_per_day_is_zero_filled_and_counts_successes(): void
    {
        $this->attempt(['outcome' => 'success', 'created_at' => now()]);
        $this->attempt(['outcome' => 'success', 'created_at' => now()]);
        $this->attempt(['outcome' => 'failure', 'created_at' => now()]); // not counted

        $series = (new MapArchiveStats('local'))->downloadsPerDay(7);

        $this->assertCount(7, $series);
        $this->assertSame(2, $series[now()->toDateString()]);
        $this->assertSame(0, $series[now()->subDays(6)->toDateString()]);
    }

    public function test_failing_servers_ranks_by_failures_with_success_rate(): void
    {
        $busy = Server::factory()->create(['name' => 'Busy']);
        $okish = Server::factory()->create(['name' => 'Okish']);
        foreach (range(1, 3) as $_) { $this->attempt(['server_id' => $busy->id, 'outcome' => 'failure', 'reason' => 'timeout', 'created_at' => now()]); }
        $this->attempt(['server_id' => $okish->id, 'outcome' => 'failure', 'reason' => 'timeout', 'created_at' => now()]);
        $this->attempt(['server_id' => $okish->id, 'outcome' => 'success', 'created_at' => now()]);

        $servers = (new MapArchiveStats('local'))->failingServers(30, 10);

        $this->assertSame('Busy', $servers[0]['name']);
        $this->assertSame(3, $servers[0]['fails']);
        $this->assertSame('timeout', $servers[0]['lastReason']);
        $this->assertSame('Okish', $servers[1]['name']);
        $this->assertSame(50, (int) round($servers[1]['successRate']));
    }

    public function test_recent_failures_excludes_mirror_only_404s(): void
    {
        $map = Map::create(['name' => 'M']);
        $v = MapVersion::create(['map_id' => $map->id, 'crc' => 'deadbeef']);
        // The expected first-source miss (retried over UDP in the same pass) must be hidden…
        $this->attempt(['map_version_id' => $v->id, 'source' => 'http_mirror', 'reason' => 'not_mirrored_404', 'created_at' => now()]);
        // …while a genuine server-download failure is shown.
        $this->attempt(['map_version_id' => $v->id, 'source' => 'udp', 'reason' => 'timeout', 'created_at' => now()]);

        $failures = (new MapArchiveStats('local'))->recentFailures(25);

        $this->assertCount(1, $failures);
        $this->assertSame('timeout', $failures->first()->reason);
    }

    public function test_source_breakdown_counts_per_source(): void
    {
        $this->attempt(['source' => 'http_mirror', 'outcome' => 'success', 'created_at' => now()]);
        $this->attempt(['source' => 'http_mirror', 'outcome' => 'failure', 'created_at' => now()]);
        $this->attempt(['source' => 'udp', 'outcome' => 'failure', 'created_at' => now()]);

        $b = (new MapArchiveStats('local'))->sourceBreakdown(30);

        $this->assertSame(['success' => 1, 'failure' => 1], $b['http_mirror']);
        $this->assertSame(['success' => 0, 'failure' => 1], $b['udp']);
    }
}
