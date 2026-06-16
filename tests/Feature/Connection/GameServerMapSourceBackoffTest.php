<?php

namespace Tests\Feature\Connection;

use App\Models\Map;
use App\Models\MapVersion;
use App\Models\Mod;
use App\Models\Server;
use App\Models\ServerAddress;
use App\Models\ServerHistory;
use App\Models\ServerMapBackoff;
use App\Service\GameServerMapSource;
use App\TwStats\Connection\ConnectionFailed;
use App\TwStats\Connection\ConnectionInterface;
use App\TwStats\Connection\DdnetUuid;
use App\TwStats\Protocol\Seven\VariableInt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The (host, map) UDP backoff filter in downloadFromCandidates: a pair that just failed a UDP
 * download is parked, so it must drop out of the candidate set. Keying on the pair (not the host)
 * means a host that has cycled to a *different* map is still dialed for that map.
 */
class GameServerMapSourceBackoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_host_in_backoff_for_this_map_is_skipped(): void
    {
        $map = Map::create(['name' => 'Parked']);
        $version = MapVersion::create(['map_id' => $map->id, 'crc' => 'deadbeef']);
        $server = $this->liveHostServing($version, $map);

        // The only candidate is parked for this exact (host, map): it must be filtered before any dial.
        ServerMapBackoff::recordFailure($server->id, $map->id);

        $dialed = 0;
        $source = new GameServerMapSource(connectionFactory: function () use (&$dialed): ConnectionInterface {
            $dialed++;
            throw new ConnectionFailed('a host in backoff for this map must never be dialed');
        });

        $result = $source->fetchVersion($version);

        self::assertNull($result);
        self::assertSame(0, $dialed, 'a host in backoff for this map must never be dialed');
        // The only candidate was filtered out → the version collapses to a single no_hosts signal.
        $this->assertDatabaseHas('map_acquisition_attempts', [
            'map_version_id' => $version->id, 'server_id' => null,
            'source' => 'udp', 'outcome' => 'failure', 'reason' => 'no_hosts',
        ]);
    }

    public function test_same_host_in_backoff_for_a_different_map_is_still_dialed(): void
    {
        $map = Map::create(['name' => 'Wanted']);
        $version = MapVersion::create(['map_id' => $map->id, 'crc' => 'feedface']);
        $server = $this->liveHostServing($version, $map);

        // The host is parked for an unrelated map; keying on the pair means it is still a candidate here.
        $otherMap = Map::create(['name' => 'Unrelated']);
        ServerMapBackoff::recordFailure($server->id, $otherMap->id);

        $dialed = 0;
        $source = new GameServerMapSource(connectionFactory: function () use (&$dialed): ConnectionInterface {
            $dialed++;
            throw new ConnectionFailed('candidate inclusion is what we assert');
        });

        $source->fetchVersion($version);

        self::assertGreaterThanOrEqual(1, $dialed, 'a host parked for a different map must still be dialed');
    }

    public function test_an_expired_backoff_does_not_skip_the_host(): void
    {
        $map = Map::create(['name' => 'Cooled']);
        $version = MapVersion::create(['map_id' => $map->id, 'crc' => 'cafebabe']);
        $server = $this->liveHostServing($version, $map);

        // Record then force the cooldown into the past: an expired pair is acquirable again.
        ServerMapBackoff::recordFailure($server->id, $map->id);
        DB::table('server_map_backoffs')->update(['cooldown_until' => now()->subHour()]);

        $dialed = 0;
        $source = new GameServerMapSource(connectionFactory: function () use (&$dialed): ConnectionInterface {
            $dialed++;
            throw new ConnectionFailed('candidate inclusion is what we assert');
        });

        $source->fetchVersion($version);

        self::assertGreaterThanOrEqual(1, $dialed, 'a host whose backoff has expired must be dialed');
    }

    /** A vanilla-06 host with a live (within the live window) history row for the given version. */
    private function liveHostServing(MapVersion $version, Map $map): Server
    {
        $server = Server::factory()->create(['flavor' => 'vanilla_06']);
        ServerAddress::factory()->create(['server_id' => $server->id, 'ip' => '10.0.0.9', 'port' => 8303, 'protocol' => 6, 'is_canonical' => true]);
        $mod = Mod::firstOrCreate(['name' => 'DM']);
        $hist = ServerHistory::create(['weekday' => 1, 'hour' => 12, 'continuous' => true, 'server_id' => $server->id, 'map_id' => $map->id, 'map_version_id' => $version->id, 'mod_id' => $mod->id, 'minutes' => 10]);
        DB::table('server_histories')->where('id', $hist->id)->update(['updated_at' => now()]);

        return $server;
    }
}
