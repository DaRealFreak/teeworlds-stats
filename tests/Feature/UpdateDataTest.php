<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\Server;
use App\TwStats\Discovery\Teeworlds06Source;
use App\TwStats\Discovery\Teeworlds07Source;
use App\TwStats\Protocol\Seven\SevenConnless;
use App\TwStats\Protocol\Seven\VariableInt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeUdpTransport;
use Tests\TestCase;

class UpdateDataTest extends TestCase
{
    use RefreshDatabase;

    private function fakeMaster(): void
    {
        // the DdnetHttpSource tries master1 first; serve it the Phase 2 fixture (3 valid servers)
        Http::fake([
            'master1.ddnet.org/*' => Http::response(
                file_get_contents(base_path('tests/Fixtures/ddnet_servers.json')),
                200,
            ),
        ]);
    }

    private function bindEmptySevenSource(): void
    {
        // no masters + empty transport → the 0.7 source contributes nothing (no live UDP in tests)
        $this->app->instance(Teeworlds07Source::class, new Teeworlds07Source(new FakeUdpTransport(), masters: []));
    }

    private function bindEmptySixSource(): void
    {
        // no masters + empty transport → the 0.6 source contributes nothing (no live UDP in tests)
        $this->app->instance(Teeworlds06Source::class, new Teeworlds06Source(new FakeUdpTransport(), masters: []));
    }

    private function bindSixSourceWithServer(string $serverName, string $playerName): void
    {
        $masterIp = '10.9.0.2';
        $serverIp = '198.51.100.6';
        $transport = new FakeUdpTransport();

        $entry = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" . inet_pton($serverIp) . "\x20\x6f"; // :8303
        $list = "\xff\xff\xff\xff\xff\xff\xff\xff\xff\xfflis2" . $entry;
        $info = "\xff\xff\xff\xff\xff\xff\xff\xff\xff\xffinf3" . implode("\x00", [
            '1', '0.6.4', $serverName, 'dm1', 'dm', '0', '1', '16', '1', '16',
            $playerName, '', '0', '3', '1',
        ]) . "\x00";

        $transport->queue($masterIp, 8300, $list);
        $transport->queueGap();
        $transport->queue($serverIp, 8303, $info);
        $transport->queueGap();

        $this->app->instance(Teeworlds06Source::class, new Teeworlds06Source($transport, masters: [['ip' => $masterIp, 'port' => 8300]]));
    }

    private function bindSevenSourceWithPlayer(string $serverName, string $playerName): void
    {
        $masterIp = '10.9.0.1';
        $serverIp = '198.51.100.7';
        $myToken = Teeworlds07Source::CLIENT_TOKEN;
        $transport = new FakeUdpTransport();

        $tokenResponse = fn (int $t) => "\x04\x00\x00\xff\xff\xff\xff" . "\x05" . pack('N', $t);
        $entry = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" . inet_pton($serverIp) . "\x20\x6f"; // :8303
        $list = SevenConnless::connless(0xA1, $myToken, "\xff\xff\xff\xfflis2" . $entry);

        $int = fn (int $v) => VariableInt::pack($v);
        $str = fn (string $s) => $s . "\x00";
        $payload = $int(0) . $str('0.7.5') . $str($serverName) . $str('h') . $str('dm1') . $str('DM')
            . $int(0) . $int(1) . $int(1) . $int(8) . $int(1) . $int(8)
            . $str($playerName) . $str('') . $int(0) . $int(0) . $int(0);
        $info = SevenConnless::connless(0xB2, $myToken, "\xff\xff\xff\xffinf3" . $payload);

        $transport->queue($masterIp, 8283, $tokenResponse(0xA1)); $transport->queueGap();
        $transport->queue($masterIp, 8283, $list); $transport->queueGap();
        $transport->queue($serverIp, 8303, $tokenResponse(0xB2)); $transport->queueGap();
        $transport->queue($serverIp, 8303, $info); $transport->queueGap();

        $this->app->instance(Teeworlds07Source::class, new Teeworlds07Source($transport, masters: [['ip' => $masterIp, 'port' => 8283]]));
    }

    public function test_it_ingests_the_ddnet_master_into_logical_servers_and_addresses(): void
    {
        $this->bindEmptySevenSource();
        $this->bindEmptySixSource();
        $this->fakeMaster();

        $this->artisan('data:update')->assertSuccessful();

        $this->assertDatabaseCount('servers', 3);
        // dual-stack DDNet server has 2 addresses; the two vanilla servers 1 each
        $this->assertDatabaseCount('server_addresses', 4);
        $this->assertDatabaseHas('servers', ['name' => 'DDNet GER10', 'flavor' => 'ddnet']);
        $this->assertDatabaseHas('servers', ['name' => 'Vanilla 0.7 CTF', 'flavor' => 'vanilla_07']);
        $this->assertDatabaseHas('servers', ['name' => 'Vanilla 0.6 DM', 'flavor' => 'vanilla_06']);

        $this->assertSame([6, 7], Server::where('name', 'DDNet GER10')->first()->protocols());
    }

    public function test_it_persists_players_with_their_cosmetic_snapshot(): void
    {
        $this->bindEmptySevenSource();
        $this->bindEmptySixSource();
        $this->fakeMaster();

        $this->artisan('data:update')->assertSuccessful();

        // vin, glow (DDNet GER10) + Bob (Vanilla 0.7 CTF); the scalar client entry is skipped
        $this->assertDatabaseCount('players', 3);

        $vin = Player::where('name', 'vin')->first();
        $this->assertSame('glow_cammo', $vin->skin);
        $this->assertSame(16726016, $vin->color_body);
        $this->assertSame(16745499, $vin->color_feet);
        $this->assertFalse($vin->afk);

        $glow = Player::where('name', 'glow')->first();
        $this->assertTrue($glow->afk);
        $this->assertSame(['name' => 'standard', 'color' => 65408], $glow->skin_parts['body']);
    }

    public function test_it_records_server_and_player_histories_and_opens_sessions(): void
    {
        $this->bindEmptySevenSource();
        $this->bindEmptySixSource();
        $this->fakeMaster();

        $this->artisan('data:update')->assertSuccessful();

        // one server_history row per persisted server, one open session per player
        $this->assertDatabaseCount('server_histories', 3);
        $this->assertDatabaseCount('player_histories', 3);
        $this->assertDatabaseCount('player_sessions', 3);
        $this->assertDatabaseHas('player_sessions', ['ended_at' => null]);
    }

    public function test_it_ingests_a_vanilla_07_server_from_the_seven_source(): void
    {
        Http::fake(['master1.ddnet.org/*' => Http::response('{"servers":[]}', 200)]);
        $this->bindEmptySixSource();
        $this->bindSevenSourceWithPlayer('Vanilla 0.7 DM', 'Sevenplayer');

        $this->artisan('data:update')->assertSuccessful();

        $this->assertDatabaseHas('servers', ['name' => 'Vanilla 0.7 DM', 'flavor' => 'vanilla_07']);
        $this->assertDatabaseHas('players', ['name' => 'Sevenplayer']);
        $this->assertDatabaseHas('server_addresses', ['ip' => '198.51.100.7', 'port' => 8303, 'protocol' => 7]);
    }

    public function test_a_seven_source_observation_does_not_wipe_a_ddnet_cosmetic_snapshot(): void
    {
        $this->fakeMaster(); // DDNet fixture: server "DDNet GER10" with player vin (skin glow_cammo)
        $this->bindEmptySixSource();
        $this->bindSevenSourceWithPlayer('Vanilla 0.7 DM', 'vin');

        $this->artisan('data:update')->assertSuccessful();

        $vin = Player::where('name', 'vin')->first();
        $this->assertSame('glow_cammo', $vin->skin);
        $this->assertFalse($vin->afk);
    }

    public function test_it_ingests_a_vanilla_06_server_from_the_six_source(): void
    {
        Http::fake(['master1.ddnet.org/*' => Http::response('{"servers":[]}', 200)]);
        $this->bindEmptySevenSource();
        $this->bindSixSourceWithServer('Vanilla 0.6 DM', 'Sixplayer');

        $this->artisan('data:update')->assertSuccessful();

        $this->assertDatabaseHas('servers', ['name' => 'Vanilla 0.6 DM', 'flavor' => 'vanilla_06']);
        $this->assertDatabaseHas('players', ['name' => 'Sixplayer']);
        $this->assertDatabaseHas('server_addresses', ['ip' => '198.51.100.6', 'port' => 8303, 'protocol' => 6]);
    }
}
