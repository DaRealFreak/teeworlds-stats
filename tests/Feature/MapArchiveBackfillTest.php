<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapVersion;
use App\Models\Mod;
use App\Models\Server;
use App\Models\ServerHistory;
use App\Service\MapArchiveBackfiller;
use App\Service\MapFileStore;
use App\Service\MapSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MapArchiveBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** A valid-looking datafile so MapFileStore's verification accepts the bytes. */
    private function bytes(): string
    {
        return 'DATA' . str_repeat("\x00", 64);
    }

    /** A source that always serves bytes — stands in for a reachable UDP host or HTTP mirror. */
    private function servingSource(): MapSource
    {
        return new class($this->bytes()) implements MapSource {
            public function __construct(private readonly string $bytes) {}
            public function fetch(Map $map): ?string { return $this->bytes; }
            public function fetchVersion(MapVersion $version): ?string { return $this->bytes; }
        };
    }

    /** A source that never serves — stands in for "not on the HTTP mirror" / unreachable. */
    private function emptySource(): MapSource
    {
        return new class implements MapSource {
            public function fetch(Map $map): ?string { return null; }
            public function fetchVersion(MapVersion $version): ?string { return null; }
        };
    }

    private function backfiller(MapSource $full, MapSource $http): MapArchiveBackfiller
    {
        return new MapArchiveBackfiller(
            fullStore: new MapFileStore(sources: [$full]),
            httpStore: new MapFileStore(sources: [$http]),
        );
    }

    private function version(string $name, ?string $sha256 = null, ?string $crc = null): MapVersion
    {
        $map = Map::create(['name' => $name]);

        return MapVersion::create([
            'map_id' => $map->id,
            'sha256' => $sha256,
            'crc' => $crc,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    /** Record that a server served this version, with a controllable freshness (updated_at is guarded). */
    private function served(MapVersion $version, bool $recent): void
    {
        $server = Server::factory()->create();
        $mod = Mod::firstOrCreate(['name' => 'TestMod']);
        $history = ServerHistory::create([
            'weekday' => 0, 'hour' => 0, 'continuous' => false,
            'server_id' => $server->id, 'map_id' => $version->map_id,
            'map_version_id' => $version->id, 'mod_id' => $mod->id, 'minutes' => 10,
        ]);
        DB::table('server_histories')->where('id', $history->id)
            ->update(['updated_at' => $recent ? now() : now()->subHours(2)]);
    }

    public function test_acquires_a_udp_only_version_a_server_is_serving_now(): void
    {
        // crc-only (no HTTP mirror); the HTTP store cannot serve it, only the full (UDP) store can.
        $version = $this->version('Live', crc: 'deadbeef');
        $this->served($version, recent: true);

        $result = $this->backfiller(full: $this->servingSource(), http: $this->emptySource())
            ->run(limit: 0, timeBudgetS: 60);

        $this->assertTrue((new MapFileStore())->isCachedVersion($version->fresh()));
        $this->assertSame(1, $result['udpTargeted']);
        $this->assertNotNull($version->fresh()->last_acquire_attempt_at);
    }

    public function test_does_not_dial_udp_for_a_version_no_server_currently_serves(): void
    {
        // Same UDP-only map, but last served 2h ago — outside the live window. The full store WOULD
        // serve it, but the gate routes it to the HTTP-only store, which cannot, so nothing is dialled.
        $version = $this->version('Stale', crc: 'deadbeef');
        $this->served($version, recent: false);

        $result = $this->backfiller(full: $this->servingSource(), http: $this->emptySource())
            ->run(limit: 0, timeBudgetS: 60);

        $this->assertFalse((new MapFileStore())->isCachedVersion($version->fresh()));
        $this->assertSame(0, $result['udpTargeted']);
        // still stamped, so the failure parks for the cooldown rather than being retried every run
        $this->assertNotNull($version->fresh()->last_acquire_attempt_at);
    }

    public function test_drains_http_mirrored_versions_with_no_live_host(): void
    {
        // sha256 version nobody currently serves: the HTTP store fetches it without any UDP attempt.
        $version = $this->version('Mirrored', sha256: hash('sha256', $this->bytes()));

        $result = $this->backfiller(full: $this->emptySource(), http: $this->servingSource())
            ->run(limit: 0, timeBudgetS: 60);

        $this->assertTrue((new MapFileStore())->isCachedVersion($version->fresh()));
        $this->assertSame(1, $result['acquired']);
        $this->assertSame(0, $result['udpTargeted']);
    }

    public function test_http_only_flag_never_dials_a_live_host(): void
    {
        // Currently served, but --http-only forces the HTTP store even for a live version.
        $version = $this->version('LiveButHttpOnly', crc: 'deadbeef');
        $this->served($version, recent: true);

        $result = $this->backfiller(full: $this->servingSource(), http: $this->emptySource())
            ->run(limit: 0, timeBudgetS: 60, httpOnly: true);

        $this->assertFalse((new MapFileStore())->isCachedVersion($version->fresh()));
        $this->assertSame(0, $result['udpTargeted']);
    }

    public function test_skips_versions_in_cooldown_unless_forced(): void
    {
        $version = $this->version('Cooling', sha256: hash('sha256', $this->bytes()));
        $version->update(['last_acquire_attempt_at' => now()->subMinutes(5)]); // within the 6h cooldown

        $skipped = $this->backfiller(full: $this->emptySource(), http: $this->servingSource())
            ->run(limit: 0, timeBudgetS: 60);
        $this->assertSame(0, $skipped['attempted'], 'a cooling-down version is not attempted');

        $forced = $this->backfiller(full: $this->emptySource(), http: $this->servingSource())
            ->run(limit: 0, timeBudgetS: 60, includeCooldown: true);
        $this->assertSame(1, $forced['attempted'], '--include-cooldown retries it');
        $this->assertTrue((new MapFileStore())->isCachedVersion($version->fresh()));
    }

    public function test_honors_the_per_run_limit(): void
    {
        $this->version('A', sha256: hash('sha256', $this->bytes()));
        $this->version('B', sha256: hash('sha256', $this->bytes()));

        $result = $this->backfiller(full: $this->emptySource(), http: $this->servingSource())
            ->run(limit: 1, timeBudgetS: 60);

        $this->assertSame(1, $result['attempted'], 'only one version attempted under the limit');
    }

    public function test_command_runs_and_acquires(): void
    {
        $version = $this->version('CmdLive', crc: 'deadbeef');
        $this->served($version, recent: true);

        // bind a backfiller whose stores never touch the network (both serve the fixture bytes)
        $this->app->instance(MapArchiveBackfiller::class, $this->backfiller(
            full: $this->servingSource(),
            http: $this->servingSource(),
        ));

        $this->artisan('maps:archive-backfill', ['--time-budget' => 60])
            ->assertSuccessful();

        $this->assertTrue((new MapFileStore())->isCachedVersion($version->fresh()));
    }
}
