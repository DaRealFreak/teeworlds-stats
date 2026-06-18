<?php

declare(strict_types=1);

namespace Tests\Feature\Connection;

use App\Models\Map;
use App\Models\MapVersion;
use App\Models\Mod;
use App\Models\Server;
use App\Models\ServerAddress;
use App\Models\ServerHistory;
use App\Service\CapturedMapVersionRecorder;
use App\Service\CurrentServerMapResolverInterface;
use App\Service\GameServerMapSource;
use App\Service\MapCachePath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GameServerMapSourceCaptureTest extends TestCase
{
    use RefreshDatabase;
    use ScriptsMapDownloads;

    /** A resolver stub that always reports the given current map name (or null). */
    private function resolver(?string $currentMap): CurrentServerMapResolverInterface
    {
        return new class($currentMap) implements CurrentServerMapResolverInterface {
            public function __construct(private readonly ?string $name) {}
            public function currentMapName(Server $server): ?string { return $this->name; }
        };
    }

    private function source(\App\TwStats\Connection\ConnectionInterface $conn, CurrentServerMapResolverInterface $resolver): GameServerMapSource
    {
        return new GameServerMapSource(
            connectionFactory: fn () => $conn,
            capturer: new CapturedMapVersionRecorder(disk: 'local'),
            mapResolver: $resolver,
        );
    }

    public function test_same_name_new_crc_is_captured_and_the_phantom_is_throttled(): void
    {
        Storage::fake('local');
        $map = Map::create(['name' => 'ctf4_old']);
        $requested = MapVersion::create(['map_id' => $map->id, 'crc' => '64bbdea4']);
        $server = Server::factory()->create(['flavor' => 'vanilla_06']);

        $payload = 'DATA' . str_repeat("\x00", 40);
        $servedCrc = crc32($payload);
        $servedCrcHex = sprintf('%08x', $servedCrc & 0xFFFFFFFF);
        $conn = $this->connectionYielding($this->mapChange('ctf4_old', $servedCrc, strlen($payload)), $this->mapData(1, $servedCrc, 0, $payload));

        // master still advertises ctf4_old → confirmed phantom
        $result = $this->source($conn, $this->resolver('ctf4_old'))
            ->downloadFromHost('9.9.9.9', 8303, 'vanilla_06', null, (int) hexdec('64bbdea4'), 'ctf4_old', 5000, $server->id, $requested->id);

        self::assertNull($result, 'the requested version was not served');

        $served = MapVersion::where('map_id', $map->id)->where('crc', $servedCrcHex)->first();
        self::assertNotNull($served, 'the served version is recorded under the same map');
        self::assertSame(MapVersion::STATUS_CACHED, $served->acquisition_status);
        Storage::disk('local')->assertExists(MapCachePath::forIdentity('ctf4_old', $served->sha256, $servedCrcHex));

        self::assertNotNull($requested->fresh()->last_acquire_attempt_at, 'the phantom is throttled');
        $this->assertDatabaseHas('map_acquisition_attempts', ['map_version_id' => $requested->id, 'source' => 'udp', 'outcome' => 'failure', 'reason' => 'served_other_version']);
        $this->assertDatabaseHas('map_acquisition_attempts', ['map_version_id' => $served->id, 'source' => 'udp', 'outcome' => 'success']);
    }

    public function test_different_name_city_server_is_captured_under_its_real_name(): void
    {
        Storage::fake('local');
        $map = Map::create(['name' => 'Multiworld']);
        $requested = MapVersion::create(['map_id' => $map->id, 'sha256' => str_repeat('a', 64)]);
        $server = Server::factory()->create(['flavor' => 'ddnet']);

        $payload = 'DATA' . str_repeat("\x02", 40);
        $crc = crc32($payload);
        $conn = $this->connectionYielding($this->mapChange('Gridania', $crc, strlen($payload)), $this->mapData(1, $crc, 0, $payload));

        $result = $this->source($conn, $this->resolver('Multiworld')) // master still says Multiworld → phantom
            ->downloadFromHost('9.9.9.9', 8303, 'ddnet', str_repeat('a', 64), null, 'Multiworld', 5000, $server->id, $requested->id);

        self::assertNull($result);
        $gridania = Map::where('name', 'Gridania')->first();
        self::assertNotNull($gridania, 'the served map is recorded under its real wire name');
        self::assertSame(1, MapVersion::where('map_id', $gridania->id)->where('acquisition_status', MapVersion::STATUS_CACHED)->count());
        self::assertNotNull($requested->fresh()->last_acquire_attempt_at);
    }

    public function test_served_other_but_server_moved_on_is_captured_without_a_throttle(): void
    {
        Storage::fake('local');
        $map = Map::create(['name' => 'ctf4_old']);
        $requested = MapVersion::create(['map_id' => $map->id, 'crc' => '64bbdea4']);
        $server = Server::factory()->create(['flavor' => 'vanilla_06']);

        $payload = 'DATA' . str_repeat("\x03", 40);
        $crc = crc32($payload);
        $conn = $this->connectionYielding($this->mapChange('ctf4_old', $crc, strlen($payload)), $this->mapData(1, $crc, 0, $payload));

        // master now reports a DIFFERENT map → the host genuinely cycled, not a phantom
        $result = $this->source($conn, $this->resolver('AnotherMap'))
            ->downloadFromHost('9.9.9.9', 8303, 'vanilla_06', null, (int) hexdec('64bbdea4'), 'ctf4_old', 5000, $server->id, $requested->id);

        self::assertNull($result);
        self::assertNotNull(MapVersion::where('map_id', $map->id)->where('crc', sprintf('%08x', $crc & 0xFFFFFFFF))->first(), 'still captured');
        self::assertNull($requested->fresh()->last_acquire_attempt_at, 'a cycled host is not throttled');
        $this->assertDatabaseHas('map_acquisition_attempts', ['map_version_id' => $requested->id, 'reason' => 'crc_mismatch']);
    }

    public function test_resolver_failure_falls_back_to_the_freshest_record(): void
    {
        Storage::fake('local');
        $map = Map::create(['name' => 'ctf4_old']);
        $requested = MapVersion::create(['map_id' => $map->id, 'crc' => '64bbdea4']);
        $server = Server::factory()->create(['flavor' => 'vanilla_06']);
        $mod = Mod::firstOrCreate(['name' => 'DM']);
        $hist = ServerHistory::create(['weekday' => 1, 'hour' => 12, 'continuous' => true, 'server_id' => $server->id, 'map_id' => $map->id, 'map_version_id' => $requested->id, 'mod_id' => $mod->id, 'minutes' => 10]);
        DB::table('server_histories')->where('id', $hist->id)->update(['updated_at' => now()]);

        $payload = 'DATA' . str_repeat("\x04", 40);
        $crc = crc32($payload);
        $conn = $this->connectionYielding($this->mapChange('ctf4_old', $crc, strlen($payload)), $this->mapData(1, $crc, 0, $payload));

        // resolver returns null → fall back to the freshest server_histories row, which still references
        // the requested version → confirmed phantom → throttle.
        $result = $this->source($conn, $this->resolver(null))
            ->downloadFromHost('9.9.9.9', 8303, 'vanilla_06', null, (int) hexdec('64bbdea4'), 'ctf4_old', 5000, $server->id, $requested->id);

        self::assertNull($result);
        self::assertNotNull($requested->fresh()->last_acquire_attempt_at);
    }

    public function test_exact_match_returns_bytes_and_does_not_consult_the_resolver(): void
    {
        Storage::fake('local');
        $map = Map::create(['name' => 'ctf4_old']);
        $payload = 'DATA' . str_repeat("\x05", 40);
        $crc = crc32($payload);
        $crcHex = sprintf('%08x', $crc & 0xFFFFFFFF);
        $requested = MapVersion::create(['map_id' => $map->id, 'crc' => $crcHex]);
        $server = Server::factory()->create(['flavor' => 'vanilla_06']);

        $conn = $this->connectionYielding($this->mapChange('ctf4_old', $crc, strlen($payload)), $this->mapData(1, $crc, 0, $payload));

        // resolver would throw if consulted — proves the happy path never reaches the gate
        $throwingResolver = new class implements CurrentServerMapResolverInterface {
            public function currentMapName(Server $server): ?string { throw new \RuntimeException('must not be called on exact match'); }
        };
        $source = new GameServerMapSource(connectionFactory: fn () => $conn, capturer: new CapturedMapVersionRecorder(disk: 'local'), mapResolver: $throwingResolver);

        $result = $source->downloadFromHost('9.9.9.9', 8303, 'vanilla_06', null, (int) hexdec($crcHex), 'ctf4_old', 5000, $server->id, $requested->id);

        self::assertSame($payload, $result);
        $this->assertDatabaseHas('map_acquisition_attempts', ['map_version_id' => $requested->id, 'source' => 'udp', 'outcome' => 'success']);
    }
}
