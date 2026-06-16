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
use App\TwStats\Connection\DdnetUuid;
use App\TwStats\Protocol\Seven\VariableInt;
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

    public function test_skips_a_host_whose_last_snapshot_is_outside_the_live_window(): void
    {
        // A host last seen serving this version over an hour ago — well outside the live window — has
        // almost certainly cycled to another map (Gores rotate every few minutes). Dialing it would only
        // yield a name/crc mismatch, so it must be filtered out of the candidate set entirely: recorded
        // as no_hosts, never connected. The connection factory asserts it is never invoked.
        $map = Map::create(['name' => 'Cycled']);
        $version = MapVersion::create(['map_id' => $map->id, 'crc' => 'feedface']);
        $server = Server::factory()->create(['flavor' => 'vanilla_06']);
        ServerAddress::factory()->create(['server_id' => $server->id, 'ip' => '10.0.0.9', 'port' => 8303, 'protocol' => 6, 'is_canonical' => true]);
        $mod = Mod::firstOrCreate(['name' => 'DM']);
        $hist = ServerHistory::create(['weekday' => 1, 'hour' => 12, 'continuous' => true, 'server_id' => $server->id, 'map_id' => $map->id, 'map_version_id' => $version->id, 'mod_id' => $mod->id, 'minutes' => 10]);
        DB::table('server_histories')->where('id', $hist->id)->update(['updated_at' => now()->subMinutes(60)]);

        $dialed = 0;
        $source = new GameServerMapSource(connectionFactory: function () use (&$dialed): ConnectionInterface {
            $dialed++;
            throw new \App\TwStats\Connection\ConnectionFailed('a stale-history host must never be dialed');
        });
        $result = $source->fetchVersion($version);

        self::assertNull($result);
        self::assertSame(0, $dialed, 'a host outside the live window must never be dialed');
        $this->assertDatabaseHas('map_acquisition_attempts', [
            'map_version_id' => $version->id, 'server_id' => null,
            'source' => 'udp', 'outcome' => 'failure', 'reason' => 'no_hosts',
        ]);
        $this->assertDatabaseMissing('map_acquisition_attempts', [
            'map_version_id' => $version->id, 'reason' => 'connect_failed',
        ]);
    }

    public function test_udp_fallback_after_a_blocked_http_mirror_is_recorded(): void
    {
        // A Cloudflare-fronted fast-download server: it advertises an HTTP mirror that 403s our
        // server-side client, but still streams the map over UDP. The recovery must be recorded under
        // the 'udp_fallback' source so a clean UDP serve and a post-block recovery stay distinguishable.
        $payload = 'DATA' . str_repeat("\x00", 40);
        $crc     = crc32($payload);
        $crcHex  = sprintf('%08x', $crc);
        $sha     = hash('sha256', $payload);
        $url     = 'https://1.1.1.1/Custom_' . $sha . '.map';

        $map = Map::create(['name' => 'Custom']);
        $version = MapVersion::create(['map_id' => $map->id, 'sha256' => $sha, 'crc' => $crcHex, 'size' => strlen($payload)]);
        $server = Server::factory()->create(['flavor' => 'vanilla_06']);
        ServerAddress::factory()->create(['server_id' => $server->id, 'ip' => '10.0.0.9', 'port' => 8303, 'protocol' => 6, 'is_canonical' => true]);
        $mod = Mod::firstOrCreate(['name' => 'DM']);
        $hist = ServerHistory::create(['weekday' => 1, 'hour' => 12, 'continuous' => true, 'server_id' => $server->id, 'map_id' => $map->id, 'map_version_id' => $version->id, 'mod_id' => $mod->id, 'minutes' => 10]);
        DB::table('server_histories')->where('id', $hist->id)->update(['updated_at' => now()]);

        Http::fake([$url => Http::response('<!DOCTYPE html>forbidden', 403)]);

        // First dial advertises HTTP (which is blocked); the second dial — the UDP fallback — streams it.
        $details = $this->connectionYielding($this->mapDetails('Custom', $sha, strlen($payload), $url));
        $udp     = $this->connectionYielding($this->mapChange('Custom', $crc, strlen($payload)), $this->mapData(1, $crc, 0, $payload));
        $dial    = 0;
        $source  = new GameServerMapSource(connectionFactory: function () use ($details, $udp, &$dial) {
            return [$details, $udp][$dial++] ?? throw new \App\TwStats\Connection\ConnectionFailed('no more dials');
        });

        $bytes = $source->fetchVersion($version);

        self::assertSame($payload, $bytes, 'the map must be recovered over UDP after the HTTP block');
        $this->assertDatabaseHas('map_acquisition_attempts', [
            'map_version_id' => $version->id, 'server_id' => $server->id,
            'source' => 'udp_fallback', 'outcome' => 'success', 'bytes' => strlen($payload),
        ]);
    }

    /** A connection whose single receive() batch carries the given pre-built messages. */
    private function connectionYielding(string ...$messages): ConnectionInterface
    {
        return new class($messages) implements ConnectionInterface {
            public bool $disconnected = false;
            /** @var array<int, string[]> */
            private array $inbox;

            /** @param string[] $batch */
            public function __construct(array $batch)
            {
                $this->inbox = [$batch];
            }

            public function sendVital(string $message): void {}
            public function flush(): void {}
            public function receive(int $timeoutMs): array { return $this->inbox === [] ? [] : array_shift($this->inbox); }
            public function disconnect(): void { $this->disconnected = true; }
            public function isOnline(): bool { return !$this->disconnected; }
        };
    }

    private function toInt32(int $v): int
    {
        $v &= 0xFFFFFFFF;
        if ($v & 0x80000000) {
            $v -= 0x100000000;
        }

        return $v;
    }

    private function mapDetails(string $name, string $sha256Hex, int $size, string $url): string
    {
        return VariableInt::pack((0 << 1) | 1)
            . DdnetUuid::compute('map-details@ddnet.tw')
            . $name . "\x00"
            . hex2bin($sha256Hex)
            . VariableInt::pack(123)
            . VariableInt::pack($size)
            . $url . "\x00";
    }

    private function mapChange(string $name, int $crc, int $size): string
    {
        return VariableInt::pack((2 << 1) | 1)
            . $name . "\x00"
            . VariableInt::pack($this->toInt32($crc))
            . VariableInt::pack($size);
    }

    private function mapData(int $last, int $crc, int $chunk, string $data): string
    {
        return VariableInt::pack((3 << 1) | 1)
            . VariableInt::pack($last)
            . VariableInt::pack($this->toInt32($crc))
            . VariableInt::pack($chunk)
            . VariableInt::pack(strlen($data))
            . $data;
    }
}
