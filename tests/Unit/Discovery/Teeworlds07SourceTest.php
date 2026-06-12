<?php

namespace Tests\Unit\Discovery;

use App\TwStats\Discovery\Teeworlds07Source;
use App\TwStats\Protocol\Seven\SevenConnless;
use App\TwStats\Protocol\Seven\VariableInt;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeUdpTransport;

class Teeworlds07SourceTest extends TestCase
{
    private function tokenResponse(int $token): string
    {
        // 7-byte control header + [NET_CTRLMSG_TOKEN=5] + token
        return "\x04\x00\x00\xff\xff\xff\xff" . "\x05" . pack('N', $token);
    }

    private function listPacket(int $token, int $myToken, string $entries): string
    {
        return SevenConnless::connless($token, $myToken, "\xff\xff\xff\xfflis2" . $entries);
    }

    private function infoPacket(int $token, int $myToken): string
    {
        $int = fn (int $v) => VariableInt::pack($v);
        $str = fn (string $s) => $s . "\x00";
        $payload = $int(0) // browse token echo
            . $str('0.7.5') . $str('Vanilla DM') . $str('host') . $str('dm1') . $str('DM')
            . $int(0) . $int(1) . $int(1) . $int(8) . $int(1) . $int(8)
            . $str('Bob') . $str('') . $int(840) . $int(3) . $int(0);

        return SevenConnless::connless($token, $myToken, "\xff\xff\xff\xffinf3" . $payload);
    }

    public function test_discovers_servers_from_the_master_and_parses_their_info(): void
    {
        $transport = new FakeUdpTransport();
        $masterIp = '10.9.0.1';
        $serverIp = '192.0.2.50';
        $myToken = Teeworlds07Source::CLIENT_TOKEN;

        // phase 1: master token handshake
        $transport->queue($masterIp, 8283, $this->tokenResponse(0xA1A1A1A1));
        $transport->queueGap();
        // phase 2: master returns a list with one ipv4-mapped server (192.0.2.50:8303, port 0x206f)
        $entry = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff\xc0\x00\x02\x32\x20\x6f";
        $transport->queue($masterIp, 8283, $this->listPacket(0xA1A1A1A1, $myToken, $entry));
        $transport->queueGap();
        // phase 3: server token handshake
        $transport->queue($serverIp, 8303, $this->tokenResponse(0xB2B2B2B2));
        $transport->queueGap();
        // phase 4: server info
        $transport->queue($serverIp, 8303, $this->infoPacket(0xB2B2B2B2, $myToken));
        $transport->queueGap();

        $source = new Teeworlds07Source($transport, masters: [['ip' => $masterIp, 'port' => 8283]]);
        $servers = $source->fetch();

        $this->assertCount(1, $servers);
        $server = $servers[0];
        $this->assertSame('Vanilla DM', $server->name);
        $this->assertSame('dm1', $server->map);
        $this->assertSame('vanilla_07', $server->flavor);
        $this->assertSame('192.0.2.50', $server->addresses[0]->ip);
        $this->assertSame(8303, $server->addresses[0]->port);
        $this->assertSame(7, $server->addresses[0]->protocol);
        $this->assertCount(1, $server->clients);
        $this->assertSame('Bob', $server->clients[0]->name);
        $this->assertNull($server->clients[0]->afk);
    }

    public function test_returns_empty_when_no_master_answers(): void
    {
        $transport = new FakeUdpTransport(); // nothing queued → every receive is a timeout
        $source = new Teeworlds07Source($transport, masters: [['ip' => '10.9.0.1', 'port' => 8283]]);

        $this->assertSame([], $source->fetch());
    }
}
