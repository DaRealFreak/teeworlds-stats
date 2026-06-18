<?php

namespace Tests\Feature;

use App\Events\MapAcquisitionAttempted;
use App\Models\Map;
use App\Models\MapVersion;
use App\Models\Server;
use App\Models\ServerMapBackoff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The reactive state listener (App\Listeners\ApplyMapAcquisitionState) advances per-version status, the
 * per-(host,map) backoff and the per-map cooldown off the same event that drives telemetry. These cases
 * pin the transition table: host-health failures back off the (host,map) pair; only unverifiable bytes
 * cool down the map; a 404 is a classification (status flip), not a cooldown; cycled-host mismatches
 * penalise nothing.
 */
class MapAcquisitionStateTest extends TestCase
{
    use RefreshDatabase;

    private function makeVersion(): MapVersion
    {
        $map = Map::create(['name' => 'Aim']);

        return MapVersion::create(['map_id' => $map->id, 'sha256' => str_repeat('a', 64)]);
    }

    public function test_http_mirror_404_flips_status_to_udp_only_without_a_cooldown(): void
    {
        $version = $this->makeVersion();

        MapAcquisitionAttempted::dispatch($version->id, null, 'http_mirror', 'failure', 'not_mirrored_404', null);

        $version->refresh();
        self::assertSame(MapVersion::STATUS_UDP_ONLY, $version->acquisition_status);
        self::assertNotNull($version->status_checked_at);
        // a 404 is a classification, governed by the 30-day recheck — it must not stamp the 6h map cooldown
        self::assertNull($version->last_acquire_attempt_at);
    }

    public function test_http_mirror_sha_mismatch_stamps_the_map_cooldown_and_leaves_status(): void
    {
        $version = $this->makeVersion();

        MapAcquisitionAttempted::dispatch($version->id, null, 'http_mirror', 'failure', 'sha_mismatch', null);

        $version->refresh();
        self::assertNotNull($version->last_acquire_attempt_at);
        self::assertSame(MapVersion::STATUS_PENDING, $version->acquisition_status);
    }

    public function test_http_mirror_http_error_stamps_the_map_cooldown(): void
    {
        $version = $this->makeVersion();

        MapAcquisitionAttempted::dispatch($version->id, null, 'http_mirror', 'failure', 'http_error', null);

        $version->refresh();
        self::assertNotNull($version->last_acquire_attempt_at);
        self::assertSame(MapVersion::STATUS_PENDING, $version->acquisition_status);
    }

    public function test_udp_no_hosts_flips_status_to_unavailable(): void
    {
        $version = $this->makeVersion();

        MapAcquisitionAttempted::dispatch($version->id, null, 'udp', 'failure', 'no_hosts', null);

        $version->refresh();
        self::assertSame(MapVersion::STATUS_UNAVAILABLE, $version->acquisition_status);
        self::assertNotNull($version->status_checked_at);
    }

    public function test_udp_connect_failed_records_a_host_map_backoff(): void
    {
        $version = $this->makeVersion();
        $server = Server::factory()->create();

        MapAcquisitionAttempted::dispatch($version->id, $server->id, 'udp', 'failure', 'connect_failed', null);

        $this->assertDatabaseHas('server_map_backoffs', [
            'server_id' => $server->id,
            'map_id' => $version->map_id,
            'failure_streak' => 1,
        ]);
    }

    public function test_udp_timeout_and_download_failed_escalate_the_host_map_backoff(): void
    {
        $version = $this->makeVersion();
        $server = Server::factory()->create();

        MapAcquisitionAttempted::dispatch($version->id, $server->id, 'udp', 'failure', 'timeout', null);
        MapAcquisitionAttempted::dispatch($version->id, $server->id, 'udp_fallback', 'failure', 'download_failed', null);

        $this->assertDatabaseHas('server_map_backoffs', [
            'server_id' => $server->id,
            'map_id' => $version->map_id,
            'failure_streak' => 2,
        ]);
    }

    public function test_udp_cycled_host_mismatch_penalises_nothing(): void
    {
        $version = $this->makeVersion();
        $server = Server::factory()->create();

        // name_mismatch / crc_mismatch mean the host cycled to a different map — neither map nor host is at fault
        MapAcquisitionAttempted::dispatch($version->id, $server->id, 'udp', 'failure', 'name_mismatch', null);
        MapAcquisitionAttempted::dispatch($version->id, $server->id, 'udp', 'failure', 'crc_mismatch', null);

        $this->assertDatabaseCount('server_map_backoffs', 0);
        $version->refresh();
        self::assertSame(MapVersion::STATUS_PENDING, $version->acquisition_status);
        self::assertNull($version->last_acquire_attempt_at);
    }

    public function test_udp_success_clears_the_backoff_and_caches_the_version(): void
    {
        $version = $this->makeVersion();
        $server = Server::factory()->create();
        ServerMapBackoff::recordFailure($server->id, $version->map_id);
        $this->assertDatabaseCount('server_map_backoffs', 1);

        MapAcquisitionAttempted::dispatch($version->id, $server->id, 'udp', 'success', null, 4096);

        $this->assertDatabaseCount('server_map_backoffs', 0);
        $version->refresh();
        self::assertSame(MapVersion::STATUS_CACHED, $version->acquisition_status);
    }

    public function test_http_mirror_success_caches_the_version(): void
    {
        $version = $this->makeVersion();

        MapAcquisitionAttempted::dispatch($version->id, null, 'http_mirror', 'success', null, 4096);

        $version->refresh();
        self::assertSame(MapVersion::STATUS_CACHED, $version->acquisition_status);
    }

    public function test_served_other_version_throttles_the_requested_version_without_a_backoff(): void
    {
        $map = Map::create(['name' => 'Multiworld']);
        $version = MapVersion::create(['map_id' => $map->id, 'sha256' => str_repeat('a', 64)]);
        $server = Server::factory()->create(['flavor' => 'ddnet']);

        \App\Events\MapAcquisitionAttempted::dispatch($version->id, $server->id, 'udp', 'failure', 'served_other_version', null);

        self::assertNotNull($version->fresh()->last_acquire_attempt_at, 'the requested version is throttled');
        self::assertSame(0, ServerMapBackoff::where('server_id', $server->id)->count(), 'a served-other host is healthy — no backoff');
    }

    public function test_plain_crc_mismatch_still_penalises_nothing(): void
    {
        $map = Map::create(['name' => 'dm1']);
        $version = MapVersion::create(['map_id' => $map->id, 'crc' => 'deadbeef']);
        $server = Server::factory()->create(['flavor' => 'vanilla_06']);

        \App\Events\MapAcquisitionAttempted::dispatch($version->id, $server->id, 'udp', 'failure', 'crc_mismatch', null);

        self::assertNull($version->fresh()->last_acquire_attempt_at);
        self::assertSame(0, ServerMapBackoff::where('server_id', $server->id)->count());
    }
}
