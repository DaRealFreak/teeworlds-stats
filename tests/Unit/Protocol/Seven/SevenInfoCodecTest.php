<?php

namespace Tests\Unit\Protocol\Seven;

use App\TwStats\Discovery\DiscoveredAddress;
use App\TwStats\Protocol\Seven\SevenInfoCodec;
use App\TwStats\Protocol\Seven\VariableInt;
use PHPUnit\Framework\TestCase;

class SevenInfoCodecTest extends TestCase
{
    /** build a valid inf3 0.7 payload (everything after the SERVERBROWSE_INFO token) */
    private function payload(): string
    {
        $int = fn (int $v) => VariableInt::pack($v);
        $str = fn (string $s) => $s . "\x00";

        return $int(1234)            // browse token echo
            . $str('0.7.5')          // version
            . $str('My 0.7 Server')  // name
            . $str('localhost')      // hostname
            . $str('ctf1')           // map
            . $str('CTF')            // gametype
            . $int(0)                // flags
            . $int(1)                // skill level
            . $int(1)                // num players
            . $int(16)               // max players
            . $int(1)                // num clients
            . $int(16)               // max clients
            // one client: name, clan, country, score, player_flag (0 = player)
            . $str('Alice') . $str('CLAN') . $int(276) . $int(5) . $int(0);
    }

    public function test_parses_the_0_7_info_layout_into_a_discovered_server(): void
    {
        $address = new DiscoveredAddress('192.0.2.50', 8303, 7);

        $server = (new SevenInfoCodec())->parse($this->payload(), $address);

        $this->assertNotNull($server);
        $this->assertSame('My 0.7 Server', $server->name);
        $this->assertSame('ctf1', $server->map);
        $this->assertSame('CTF', $server->gametype);
        $this->assertSame('0.7.5', $server->version);
        $this->assertSame('vanilla_07', $server->flavor);
        $this->assertSame(16, $server->maxClients);
        $this->assertSame(16, $server->maxPlayers);
        $this->assertSame([$address], $server->addresses);

        $this->assertCount(1, $server->clients);
        $client = $server->clients[0];
        $this->assertSame('Alice', $client->name);
        $this->assertSame('CLAN', $client->clan);
        $this->assertSame(276, $client->country);
        $this->assertSame(5, $client->score);
        $this->assertTrue($client->isPlayer);          // player_flag 0 -> player
        $this->assertNull($client->afk);               // 0.7 carries no afk/skin
        $this->assertNull($client->skin);
        $this->assertNull($client->skinParts);
    }

    public function test_returns_null_on_a_truncated_payload(): void
    {
        $address = new DiscoveredAddress('192.0.2.50', 8303, 7);
        $this->assertNull((new SevenInfoCodec())->parse("\x01\x02\x03", $address));
    }
}
