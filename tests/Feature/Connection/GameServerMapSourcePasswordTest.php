<?php

namespace Tests\Feature\Connection;

use App\Models\Map;
use App\Models\MapVersion;
use App\Models\Mod;
use App\Models\Server;
use App\Models\ServerAddress;
use App\Models\ServerHistory;
use App\Service\GameServerMapSource;
use App\TwStats\Connection\ConnectionFailed;
use App\TwStats\Connection\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The password-protected exclusion in downloadFromCandidates: a host whose server has FLAG_PASSWORD
 * set rejects our empty-password INFO before MAP_CHANGE, so a dial can only ever connect_failed.
 * Such hosts must be filtered out of the candidate set before any connection is attempted.
 */
class GameServerMapSourcePasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_password_protected_host_is_never_dialed(): void
    {
        $map = Map::create(['name' => 'Locked']);
        $version = MapVersion::create(['map_id' => $map->id, 'crc' => 'deadbeef']);
        // The ONLY live host serving this version is password-protected.
        $this->liveHostServing($version, $map, Server::FLAG_PASSWORD);

        $dialed = 0;
        $source = new GameServerMapSource(connectionFactory: function () use (&$dialed): ConnectionInterface {
            $dialed++;
            throw new ConnectionFailed('a password-protected host must never be dialed');
        });

        $result = $source->fetchVersion($version);

        self::assertNull($result);
        self::assertSame(0, $dialed, 'a password-protected host must never be dialed');
        // The only candidate was filtered out → the version collapses to a single no_hosts signal.
        $this->assertDatabaseHas('map_acquisition_attempts', [
            'map_version_id' => $version->id, 'server_id' => null,
            'source' => 'udp', 'outcome' => 'failure', 'reason' => 'no_hosts',
        ]);
    }

    public function test_an_open_host_serving_the_same_version_is_still_dialed(): void
    {
        $map = Map::create(['name' => 'Open']);
        $version = MapVersion::create(['map_id' => $map->id, 'crc' => 'feedface']);
        // A non-password host (flags=0) serving the same version must stay a candidate.
        $this->liveHostServing($version, $map, 0);

        $dialed = 0;
        $source = new GameServerMapSource(connectionFactory: function () use (&$dialed): ConnectionInterface {
            $dialed++;
            throw new ConnectionFailed('candidate inclusion is what we assert');
        });

        $source->fetchVersion($version);

        self::assertGreaterThanOrEqual(1, $dialed, 'an open host serving the version must still be dialed');
    }

    /** A vanilla-06 host with a live (within the live window) history row for the given version. */
    private function liveHostServing(MapVersion $version, Map $map, int $flags): Server
    {
        $server = Server::factory()->create(['flavor' => 'vanilla_06', 'flags' => $flags]);
        ServerAddress::factory()->create(['server_id' => $server->id, 'ip' => '10.0.0.9', 'port' => 8303, 'protocol' => 6, 'is_canonical' => true]);
        $mod = Mod::firstOrCreate(['name' => 'DM']);
        $hist = ServerHistory::create(['weekday' => 1, 'hour' => 12, 'continuous' => true, 'server_id' => $server->id, 'map_id' => $map->id, 'map_version_id' => $version->id, 'mod_id' => $mod->id, 'minutes' => 10]);
        DB::table('server_histories')->where('id', $hist->id)->update(['updated_at' => now()]);

        return $server;
    }
}
