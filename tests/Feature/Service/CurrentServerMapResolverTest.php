<?php

declare(strict_types=1);

namespace Tests\Feature\Service;

use App\Models\Server;
use App\Models\ServerAddress;
use App\Service\CurrentServerMapResolver;
use App\TwStats\Discovery\DdnetHttpSource;
use App\TwStats\Net\UdpTransport;
use App\TwStats\Protocol\Six\SixConnless;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CurrentServerMapResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_ddnet_server_resolves_from_the_master_snapshot_by_address(): void
    {
        Http::fake(['https://a/servers.json' => Http::response(file_get_contents(base_path('tests/Fixtures/ddnet_servers.json')), 200)]);

        $server = Server::factory()->create(['flavor' => 'ddnet']);
        ServerAddress::factory()->create(['server_id' => $server->id, 'ip' => '192.0.2.10', 'port' => 8303, 'protocol' => 6, 'is_canonical' => true]);
        $server->load('addresses');

        $resolver = new CurrentServerMapResolver(ddnetSource: new DdnetHttpSource(mirrors: ['https://a/servers.json']));

        self::assertSame('Multeasymap', $resolver->currentMapName($server));
    }

    public function test_ddnet_server_not_in_master_returns_null(): void
    {
        Http::fake(['https://a/servers.json' => Http::response(file_get_contents(base_path('tests/Fixtures/ddnet_servers.json')), 200)]);

        $server = Server::factory()->create(['flavor' => 'ddnet']);
        ServerAddress::factory()->create(['server_id' => $server->id, 'ip' => '203.0.113.99', 'port' => 8303, 'protocol' => 6, 'is_canonical' => true]);
        $server->load('addresses');

        $resolver = new CurrentServerMapResolver(ddnetSource: new DdnetHttpSource(mirrors: ['https://a/servers.json']));

        self::assertNull($resolver->currentMapName($server));
    }

    public function test_vanilla_06_server_resolves_from_a_connless_gie3_reply(): void
    {
        $server = Server::factory()->create(['flavor' => 'vanilla_06']);
        ServerAddress::factory()->create(['server_id' => $server->id, 'ip' => '198.51.100.7', 'port' => 8303, 'protocol' => 6, 'is_canonical' => true]);
        $server->load('addresses');

        // a transport that replies to the gie3 request with a scripted inf3 packet announcing "dm2"
        $transport = new class implements UdpTransport {
            public bool $sent = false;
            public function send(string $ip, int $port, string $data): void { $this->sent = true; }
            public function receive(int $timeoutMs): ?array
            {
                if (!$this->sent) { return null; }
                $this->sent = false; // one reply
                return ['ip' => '198.51.100.7', 'port' => 8303, 'data' => self::inf3('dm2')];
            }

            /** Build a connless inf3 reply: 6-byte framing, \xff\xff\xff\xff + "inf3", then the
             *  NUL-terminated fields token,version,name,map,gametype,flags,numPlayers,maxPlayers,
             *  numClients,maxClients — matching the 9-field layout SixInfoCodec::parseHeaderPacket
             *  expects after dropping the leading echoed token. */
            private static function inf3(string $map): string
            {
                $framing = str_repeat("\xff", 6);
                $command = "\xff\xff\xff\xff" . 'inf3';
                // token (dropped by codec), then the 9 header fields: version, name, map, gametype,
                // flags, numPlayers, maxPlayers, numClients, maxClients
                $fields = implode("\x00", ['1', '0.6.4', 'Test Server', $map, 'DM', '0', '0', '16', '0', '16']) . "\x00";
                return $framing . $command . $fields;
            }
        };

        $resolver = new CurrentServerMapResolver(transport: $transport);

        self::assertSame('dm2', $resolver->currentMapName($server));
    }

    public function test_unsupported_flavor_returns_null(): void
    {
        $server = Server::factory()->create(['flavor' => 'vanilla_07']);
        $server->load('addresses');

        self::assertNull((new CurrentServerMapResolver())->currentMapName($server));
    }
}
