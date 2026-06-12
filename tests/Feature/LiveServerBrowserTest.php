<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\Mod;
use App\Models\Player;
use App\Models\PlayerHistory;
use App\Models\Server;
use App\Models\ServerAddress;
use App\Models\ServerHistory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveServerBrowserTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_server_history_returns_latest_map_and_mod(): void
    {
        $server = Server::create([
            'name' => 'Relation Server', 'version' => '0.7.5', 'ip' => '127.0.0.1', 'port' => 8303,
        ]);

        $oldMap = Map::create(['name' => 'dm1']);
        $oldMod = Mod::create(['name' => 'dm']);
        $newMap = Map::create(['name' => 'ctf5']);
        $newMod = Mod::create(['name' => 'ctf']);

        $old = ServerHistory::create([
            'server_id' => $server->id, 'map_id' => $oldMap->id, 'mod_id' => $oldMod->id,
            'weekday' => 1, 'hour' => 12, 'continuous' => 1, 'minutes' => 60,
        ]);
        // backdate the old row's updated_at without the model touching it
        ServerHistory::where('id', $old->id)->update(['updated_at' => Carbon::now()->subHour()]);

        ServerHistory::create([
            'server_id' => $server->id, 'map_id' => $newMap->id, 'mod_id' => $newMod->id,
            'weekday' => 1, 'hour' => 13, 'continuous' => 1, 'minutes' => 60,
        ]);

        $history = $server->currentServerHistory;

        $this->assertNotNull($history);
        $this->assertSame('ctf5', $history->map->name);
        $this->assertSame('ctf', $history->mod->name);
    }

    /**
     * Seed one online server with a player, one online but empty server, and one
     * stale (offline) server.
     */
    private function seedBrowserFixture(): void
    {
        $map = Map::create(['name' => 'ctf5']);
        $mod = Mod::create(['name' => 'ctf']);

        // online server with one current player
        $online = Server::create([
            'name' => 'Online FFA Server', 'version' => '0.7.5', 'ip' => '10.0.0.1', 'port' => 8303,
            'last_seen' => Carbon::now(),
        ]);
        ServerHistory::create([
            'server_id' => $online->id, 'map_id' => $map->id, 'mod_id' => $mod->id,
            'weekday' => 1, 'hour' => 12, 'continuous' => 1, 'minutes' => 60,
        ]);
        $player = Player::create(['name' => 'RosterTee', 'country' => 'DE']);
        PlayerHistory::create([
            'server_id' => $online->id, 'player_id' => $player->id, 'map_id' => $map->id, 'mod_id' => $mod->id,
            'weekday' => 1, 'hour' => 12, 'continuous' => 1, 'minutes' => 60,
        ]);

        // online but empty server (no player histories)
        $empty = Server::create([
            'name' => 'Empty DM Server', 'version' => '0.7.5', 'ip' => '10.0.0.2', 'port' => 8303,
            'last_seen' => Carbon::now(),
        ]);
        ServerHistory::create([
            'server_id' => $empty->id, 'map_id' => $map->id, 'mod_id' => $mod->id,
            'weekday' => 1, 'hour' => 12, 'continuous' => 1, 'minutes' => 60,
        ]);

        // stale server, last seen well outside the online window
        Server::create([
            'name' => 'Stale Offline Server', 'version' => '0.7.5', 'ip' => '10.0.0.3', 'port' => 8303,
            'last_seen' => Carbon::now()->subHours(2),
        ]);
    }

    public function test_browser_lists_online_servers_with_current_map_mod_and_roster(): void
    {
        $this->seedBrowserFixture();

        $response = $this->get('/serverbrowser');

        $response->assertStatus(200);
        $response->assertSee('Online FFA Server');
        $response->assertSee('Empty DM Server');
        $response->assertSee('ctf5');                    // current map
        $response->assertSee('ctf');                     // current gametype
        $response->assertSee('RosterTee');               // embedded hover roster
        $response->assertSee('data-players="1"', false); // count on the populated server
        $response->assertSee('data-players="0"', false); // empty server still listed
        $response->assertSeeInOrder(['Online FFA Server', 'Empty DM Server']); // sorted by descending player count
        $response->assertSee('data-player-names="rostertee"', false); // lowercased roster powers the combined search
        $response->assertSee('data-player-names=""', false); // empty servers still render the attr so dataset.playerNames is "" not undefined
    }

    public function test_browser_excludes_stale_servers(): void
    {
        $this->seedBrowserFixture();

        $response = $this->get('/serverbrowser');

        $response->assertStatus(200);
        $response->assertDontSee('Stale Offline Server');
    }

    /**
     * Seed an online dual-stack DDNet server and an online vanilla 0.7 server, each with
     * a current map/mod and protocol-tagged addresses.
     */
    private function seedTypedServers(): void
    {
        $map = Map::create(['name' => 'Multeasymap']);
        $mod = Mod::create(['name' => 'DDraceNetwork']);

        $ddnet = Server::create([
            'name' => 'GER10 Novice', 'version' => '0.6.4, 19.1', 'flavor' => 'ddnet',
            'ip' => '10.1.0.1', 'port' => 8303, 'last_seen' => Carbon::now(),
        ]);
        ServerHistory::create([
            'server_id' => $ddnet->id, 'map_id' => $map->id, 'mod_id' => $mod->id,
            'weekday' => 1, 'hour' => 12, 'continuous' => 1, 'minutes' => 60,
        ]);
        ServerAddress::create(['server_id' => $ddnet->id, 'ip' => '10.1.0.1', 'port' => 8303, 'protocol' => 6, 'is_canonical' => true]);
        ServerAddress::create(['server_id' => $ddnet->id, 'ip' => '10.1.0.1', 'port' => 8303, 'protocol' => 7, 'is_canonical' => false]);

        $vanilla = Server::create([
            'name' => 'CTF Pro', 'version' => '0.7.5', 'flavor' => 'vanilla_07',
            'ip' => '10.1.0.2', 'port' => 8303, 'last_seen' => Carbon::now(),
        ]);
        ServerHistory::create([
            'server_id' => $vanilla->id, 'map_id' => $map->id, 'mod_id' => $mod->id,
            'weekday' => 1, 'hour' => 12, 'continuous' => 1, 'minutes' => 60,
        ]);
        ServerAddress::create(['server_id' => $vanilla->id, 'ip' => '10.1.0.2', 'port' => 8303, 'protocol' => 7, 'is_canonical' => true]);
    }

    public function test_browser_shows_server_type_badges_and_protocol_pills(): void
    {
        $this->seedTypedServers();

        $response = $this->get('/serverbrowser');

        $response->assertStatus(200);
        $response->assertSee('>DDNet</span>', false);            // flavor badge (not the server name)
        $response->assertSee('>Vanilla</span>', false);
        $response->assertSee('data-flavor="ddnet"', false);      // row attribute powers the filter
        $response->assertSee('data-flavor="vanilla_07"', false);
        $response->assertSee('>0.6</span>', false);              // dual-stack protocol pills
        $response->assertSee('>0.7</span>', false);
    }

    public function test_browser_renders_the_type_filter_control(): void
    {
        $this->seedTypedServers();

        $response = $this->get('/serverbrowser');

        $response->assertStatus(200);
        $response->assertSee('id="filter_type"', false);
        $response->assertSee('All types');
    }

    public function test_browser_shows_the_online_server_count(): void
    {
        $this->seedBrowserFixture(); // two online, one stale

        $response = $this->get('/serverbrowser');

        $response->assertStatus(200);
        $response->assertSee('id="online_server_count">(2)</span>', false);
    }

    public function test_browser_shows_player_count_over_max_clients(): void
    {
        $map = Map::create(['name' => 'ctf5']);
        $mod = Mod::create(['name' => 'ctf']);
        $server = Server::create([
            'name' => 'Capacity Server', 'version' => '0.7.5', 'ip' => '10.0.5.1', 'port' => 8303,
            'last_seen' => Carbon::now(), 'max_clients' => 64, 'max_players' => 64,
        ]);
        ServerHistory::create([
            'server_id' => $server->id, 'map_id' => $map->id, 'mod_id' => $mod->id,
            'weekday' => 1, 'hour' => 12, 'continuous' => 1, 'minutes' => 60,
        ]);
        $player = Player::create(['name' => 'CapTee', 'country' => 'DE']);
        PlayerHistory::create([
            'server_id' => $server->id, 'player_id' => $player->id, 'map_id' => $map->id, 'mod_id' => $mod->id,
            'weekday' => 1, 'hour' => 12, 'continuous' => 1, 'minutes' => 60,
        ]);

        $response = $this->get('/serverbrowser');

        $response->assertStatus(200);
        $response->assertSee('Players on this server">1/64</span>', false); // current/max in the badge
    }

    public function test_browser_falls_back_to_a_bare_count_when_max_is_unknown(): void
    {
        $this->seedBrowserFixture(); // online servers have no max_clients (legacy rows)

        $response = $this->get('/serverbrowser');

        $response->assertStatus(200);
        $response->assertSee('Players on this server">1</span>', false); // no "/" when max is unknown
        $response->assertDontSee('Players on this server">1/', false); // positively confirm no ratio slash
    }

    public function test_browser_shows_a_copyable_ip_port(): void
    {
        $this->seedBrowserFixture();

        $response = $this->get('/serverbrowser');

        $response->assertStatus(200);
        $response->assertSee('class="server-connect', false);
        $response->assertSee('data-connect="10.0.0.1:8303"', false); // the online server's join address
    }

    public function test_browser_brackets_an_ipv6_connect_address(): void
    {
        $map = Map::create(['name' => 'ctf5']);
        $mod = Mod::create(['name' => 'ctf']);
        $server = Server::create([
            'name' => 'v6 Server', 'version' => '0.7.5', 'ip' => '2001:db8::5', 'port' => 8303,
            'last_seen' => \Carbon\Carbon::now(),
        ]);
        ServerHistory::create([
            'server_id' => $server->id, 'map_id' => $map->id, 'mod_id' => $mod->id,
            'weekday' => 1, 'hour' => 12, 'continuous' => 1, 'minutes' => 60,
        ]);

        $response = $this->get('/serverbrowser');

        $response->assertStatus(200);
        $response->assertSee('data-connect="[2001:db8::5]:8303"', false);
    }
}
