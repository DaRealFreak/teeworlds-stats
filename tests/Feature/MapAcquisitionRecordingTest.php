<?php

namespace Tests\Feature;

use App\Events\MapAcquisitionAttempted;
use App\Models\Map;
use App\Models\MapAcquisitionAttempt;
use App\Models\MapVersion;
use App\Models\Mod;
use App\Models\Server;
use App\Models\ServerAddress;
use App\Models\ServerHistory;
use App\Service\DdnetHttpMapSource;
use App\Service\GameServerMapSource;
use App\TwStats\Connection\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MapAcquisitionRecordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatching_the_event_writes_a_row(): void
    {
        $map = Map::create(['name' => 'Aim']);
        $version = MapVersion::create(['map_id' => $map->id, 'sha256' => str_repeat('a', 64)]);

        // auto-discovered listener persists the row (Laravel 11+ registers app/Listeners by handle() type-hint)
        MapAcquisitionAttempted::dispatch($version->id, null, 'http_mirror', 'success', null, 4096);

        $this->assertDatabaseHas('map_acquisition_attempts', [
            'map_version_id' => $version->id,
            'server_id' => null,
            'source' => 'http_mirror',
            'outcome' => 'success',
            'bytes' => 4096,
        ]);
    }

    public function test_listener_swallows_a_write_failure(): void
    {
        // a bogus server_id violates the FK; the listener must catch it so a download is never broken
        $listener = new \App\Listeners\RecordMapAcquisitionAttempt();
        $listener->handle(new MapAcquisitionAttempted(null, 999999, 'udp', 'failure', 'timeout', null));

        $this->assertDatabaseCount('map_acquisition_attempts', 0);
    }

    public function test_http_mirror_success_is_recorded(): void
    {
        $bytes = 'DATA' . str_repeat("\x00", 64);
        $sha = hash('sha256', $bytes);
        $map = Map::create(['name' => 'Aim']);
        $version = MapVersion::create(['map_id' => $map->id, 'sha256' => $sha, 'size' => strlen($bytes)]);

        Http::fake(['maps.ddnet.org/*' => Http::response($bytes, 200)]);

        (new DdnetHttpMapSource())->fetchVersion($version);

        $this->assertDatabaseHas('map_acquisition_attempts', [
            'map_version_id' => $version->id, 'server_id' => null,
            'source' => 'http_mirror', 'outcome' => 'success', 'bytes' => strlen($bytes),
        ]);
    }

    public function test_http_mirror_404_is_recorded_as_failure(): void
    {
        $map = Map::create(['name' => 'Aim']);
        $version = MapVersion::create(['map_id' => $map->id, 'sha256' => str_repeat('b', 64)]);

        Http::fake(['maps.ddnet.org/*' => Http::response('nope', 404)]);

        (new DdnetHttpMapSource())->fetchVersion($version);

        $this->assertDatabaseHas('map_acquisition_attempts', [
            'map_version_id' => $version->id, 'source' => 'http_mirror',
            'outcome' => 'failure', 'reason' => 'not_mirrored_404',
        ]);
    }

    public function test_udp_failure_is_recorded_per_server(): void
    {
        // a host that recently served the version, but the connection factory throws → udp failure row
        $map = Map::create(['name' => 'Kobra']);
        $version = MapVersion::create(['map_id' => $map->id, 'crc' => 'deadbeef']);
        $server = Server::factory()->create(['flavor' => 'vanilla_06']);
        ServerAddress::factory()->create(['server_id' => $server->id, 'ip' => '10.0.0.9', 'port' => 8303, 'protocol' => 6, 'is_canonical' => true]);
        $mod = Mod::firstOrCreate(['name' => 'DM']);
        $hist = ServerHistory::create(['weekday' => 1, 'hour' => 12, 'continuous' => true, 'server_id' => $server->id, 'map_id' => $map->id, 'map_version_id' => $version->id, 'mod_id' => $mod->id, 'minutes' => 10]);
        DB::table('server_histories')->where('id', $hist->id)->update(['updated_at' => now()]);

        $source = new GameServerMapSource(connectionFactory: function (): ConnectionInterface {
            throw new \App\TwStats\Connection\ConnectionFailed('refused');
        });
        $source->fetchVersion($version);

        $this->assertDatabaseHas('map_acquisition_attempts', [
            'map_version_id' => $version->id, 'server_id' => $server->id,
            'source' => 'udp', 'outcome' => 'failure', 'reason' => 'connect_failed',
        ]);
    }

    public function test_no_candidate_hosts_records_no_hosts(): void
    {
        $map = Map::create(['name' => 'Lonely']);
        $version = MapVersion::create(['map_id' => $map->id, 'crc' => 'cafebabe']);

        (new GameServerMapSource())->fetchVersion($version); // no server_histories rows → no hosts

        $this->assertDatabaseHas('map_acquisition_attempts', [
            'map_version_id' => $version->id, 'server_id' => null,
            'source' => 'udp', 'outcome' => 'failure', 'reason' => 'no_hosts',
        ]);
    }
}
