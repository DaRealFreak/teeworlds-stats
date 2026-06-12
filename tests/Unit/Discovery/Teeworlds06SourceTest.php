<?php

namespace Tests\Unit\Discovery;

use App\TwStats\Discovery\Teeworlds06Source;
use App\TwStats\Protocol\Six\SixConnless;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeUdpTransport;

class Teeworlds06SourceTest extends TestCase
{
    private function lis2(array $ipPorts): string
    {
        $payload = '';
        foreach ($ipPorts as [$ip, $port]) {
            $payload .= "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" . inet_pton($ip) . chr($port >> 8) . chr($port & 0xff);
        }

        // wrap as a 0.6 lis2 response: "xe" + extra + command + payload
        return "xe\x00\x00\x00\x00\xff\xff\xff\xfflis2" . $payload;
    }

    private function inf3(array $fields): string
    {
        return "xe\x00\x00\x00\x00\xff\xff\xff\xffinf3" . implode("\x00", $fields) . "\x00";
    }

    public function test_discovers_servers_from_the_master_list_then_queries_them(): void
    {
        $transport = new FakeUdpTransport();

        // phase 1: master returns two servers
        $transport->queue('203.0.113.1', 8300, $this->lis2([['198.51.100.7', 8303], ['198.51.100.8', 8303]]));
        $transport->queueGap();

        // phase 2: each server answers inf3
        $transport->queue('198.51.100.7', 8303, $this->inf3([
            '1', '0.6.4', 'Alpha', 'dm1', 'dm', '0', '1', '16', '1', '16',
            'alice', '', '0', '5', '1',
        ]));
        $transport->queue('198.51.100.8', 8303, $this->inf3([
            '1', '0.6.4', 'Beta', 'ctf1', 'ctf', '0', '0', '16', '0', '16',
        ]));
        $transport->queueGap();

        $source = new Teeworlds06Source($transport, [['ip' => '203.0.113.1', 'port' => 8300]]);
        $servers = $source->fetch();

        $this->assertCount(2, $servers);

        $names = array_map(fn ($s) => $s->name, $servers);
        sort($names);
        $this->assertSame(['Alpha', 'Beta'], $names);

        $alpha = array_values(array_filter($servers, fn ($s) => $s->name === 'Alpha'))[0];
        $this->assertSame('198.51.100.7', $alpha->addresses[0]->ip);
        $this->assertSame(6, $alpha->addresses[0]->protocol);
        $this->assertSame('vanilla_06', $alpha->flavor);
        $this->assertCount(1, $alpha->clients);
        $this->assertSame('alice', $alpha->clients[0]->name);

        // a GETLIST went to the master and a GETINFO went to each server
        $this->assertSame(SixConnless::getList(substr($transport->sent[0]['data'], 2, 2)), $transport->sent[0]['data']);
        $sentTo = array_map(fn ($s) => $s['ip'], $transport->sent);
        $this->assertContains('198.51.100.7', $sentTo);
        $this->assertContains('198.51.100.8', $sentTo);
    }

    public function test_reassembles_extended_iext_plus_iex_plus_by_source_address(): void
    {
        $transport = new FakeUdpTransport();
        $transport->queue('203.0.113.1', 8300, $this->lis2([['198.51.100.9', 8303]]));
        $transport->queueGap();

        $iext = "xe\x00\x00\x00\x00\xff\xff\xff\xffiext" . implode("\x00", [
            '1', '0.6.4', 'Huge', 'map', '0', '0', 'mod', '0', '2', '64', '2', '64', '',
            'p1', '', '0', '0', '1', '',
        ]) . "\x00";
        $iexPlus = "xe\x00\x00\x00\x00\xff\xff\xff\xffiex+" . implode("\x00", [
            '1', '1', '',
            'p2', '', '0', '0', '1', '',
        ]) . "\x00";

        $transport->queue('198.51.100.9', 8303, $iext);
        $transport->queue('198.51.100.9', 8303, $iexPlus);
        $transport->queueGap();

        $source = new Teeworlds06Source($transport, [['ip' => '203.0.113.1', 'port' => 8300]]);
        $servers = $source->fetch();

        $this->assertCount(1, $servers);
        $this->assertSame('Huge', $servers[0]->name);
        $this->assertSame(64, $servers[0]->maxClients);
        $names = array_map(fn ($c) => $c->name, $servers[0]->clients);
        $this->assertSame(['p1', 'p2'], $names);
    }

    public function test_a_listed_server_that_never_answers_yields_no_server(): void
    {
        $transport = new FakeUdpTransport();
        $transport->queue('203.0.113.1', 8300, $this->lis2([['198.51.100.7', 8303]]));
        $transport->queueGap();
        $transport->queueGap(); // phase 2: silence

        $source = new Teeworlds06Source($transport, [['ip' => '203.0.113.1', 'port' => 8300]]);
        $this->assertSame([], $source->fetch());
    }
}
