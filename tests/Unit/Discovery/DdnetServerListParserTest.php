<?php

namespace Tests\Unit\Discovery;

use App\TwStats\Discovery\DdnetServerListParser;
use PHPUnit\Framework\TestCase;

class DdnetServerListParserTest extends TestCase
{
    private function fixture(): string
    {
        return file_get_contents(__DIR__ . '/../../Fixtures/ddnet_servers.json');
    }

    public function test_skips_malformed_entries_and_parses_the_valid_servers(): void
    {
        $servers = (new DdnetServerListParser())->parse($this->fixture());

        // 5 entries: 1 missing info, 1 with no valid tw address → 3 valid servers
        $this->assertCount(3, $servers);
    }

    public function test_parses_a_dual_stack_ddnet_server_with_both_skin_encodings(): void
    {
        $server = (new DdnetServerListParser())->parse($this->fixture())[0];

        $this->assertSame('DDNet GER10', $server->name);
        $this->assertSame('Multeasymap', $server->map);
        $this->assertSame('DDraceNetwork', $server->gametype);
        $this->assertSame('0.6.4, 19.1', $server->version);
        $this->assertSame(64, $server->maxClients);
        $this->assertSame(64, $server->maxPlayers);
        $this->assertSame('eu', $server->location);
        $this->assertSame('ddnet', $server->flavor);

        $this->assertCount(2, $server->addresses);
        $this->assertSame('192.0.2.10', $server->addresses[0]->ip);
        $this->assertSame(6, $server->addresses[0]->protocol);
        $this->assertSame(7, $server->addresses[1]->protocol);

        $this->assertCount(2, $server->clients);

        // client 1 — 0.6 skin + custom colors, not afk
        $vin = $server->clients[0];
        $this->assertSame('vin', $vin->name);
        $this->assertSame(-102, $vin->country);
        $this->assertSame(-1, $vin->score);
        $this->assertTrue($vin->isPlayer);
        $this->assertFalse($vin->afk);
        $this->assertSame('glow_cammo', $vin->skin);
        $this->assertSame(16726016, $vin->colorBody);
        $this->assertSame(16745499, $vin->colorFeet);
        $this->assertNull($vin->skinParts);

        // client 2 — 0.7 six-part skin, afk
        $glow = $server->clients[1];
        $this->assertSame('GLOW', $glow->clan);
        $this->assertTrue($glow->afk);
        $this->assertNull($glow->skin);
        $this->assertNull($glow->colorBody);
        $this->assertNull($glow->colorFeet);
        $this->assertSame(['name' => 'standard', 'color' => 65408], $glow->skinParts['body']);
    }

    public function test_parses_a_vanilla_07_server_with_an_ipv6_address(): void
    {
        $server = (new DdnetServerListParser())->parse($this->fixture())[1];

        $this->assertSame('vanilla_07', $server->flavor);
        $this->assertSame('2001:db8::5', $server->addresses[0]->ip);
        $this->assertSame(7, $server->addresses[0]->protocol);
        $this->assertCount(1, $server->clients); // the malformed scalar client (42) is skipped
        $this->assertNull($server->clients[0]->skin);
        $this->assertFalse($server->clients[0]->afk); // afk absent → false
    }

    public function test_parses_a_vanilla_06_server_with_no_clients(): void
    {
        $server = (new DdnetServerListParser())->parse($this->fixture())[2];

        $this->assertSame('vanilla_06', $server->flavor);
        $this->assertSame([], $server->clients);
    }

    public function test_captures_ddnet_map_identity_and_leaves_it_null_when_absent(): void
    {
        $servers = (new DdnetServerListParser())->parse($this->fixture());

        $this->assertSame('aabbccddeeff00112233445566778899aabbccddeeff00112233445566778899', $servers[0]->mapSha256);
        $this->assertSame(654321, $servers[0]->mapSize);
        $this->assertNull($servers[0]->mapCrc);

        // the vanilla 0.7 server carries no sha256/size in the feed
        $this->assertNull($servers[1]->mapSha256);
        $this->assertNull($servers[1]->mapSize);
    }

    public function test_rejects_a_malformed_map_sha256(): void
    {
        $json = json_encode(['servers' => [[
            'addresses' => ['tw-0.6+udp://192.0.2.1:8303'],
            'location' => 'eu',
            'info' => [
                'name' => 'S', 'map' => ['name' => 'm', 'sha256' => 'XYZ', 'size' => 10],
                'game_type' => 'DM', 'version' => '0.6.4', 'max_clients' => 8, 'max_players' => 8, 'clients' => [],
            ],
        ]]]);

        $servers = (new DdnetServerListParser())->parse($json);

        $this->assertCount(1, $servers);
        $this->assertNull($servers[0]->mapSha256); // 'XYZ' is not 64-char hex → rejected
        $this->assertSame(10, $servers[0]->mapSize);
    }

    public function test_returns_empty_for_unusable_json(): void
    {
        $parser = new DdnetServerListParser();

        $this->assertSame([], $parser->parse('not json'));
        $this->assertSame([], $parser->parse('{"nope": 1}'));
        $this->assertSame([], $parser->parse('{"servers": "nope"}'));
    }
}
