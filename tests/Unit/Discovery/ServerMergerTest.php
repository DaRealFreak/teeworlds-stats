<?php

namespace Tests\Unit\Discovery;

use App\TwStats\Model\DiscoveredAddress;
use App\TwStats\Model\DiscoveredClient;
use App\TwStats\Model\DiscoveredServer;
use App\TwStats\Discovery\ServerMerger;
use PHPUnit\Framework\TestCase;

class ServerMergerTest extends TestCase
{
    private function client(string $name, string $clan = ''): DiscoveredClient
    {
        return new DiscoveredClient($name, $clan, 0, 0, true, false, null, null, null, null);
    }

    private function server(array $addresses, array $clients, string $name = 'S', string $flavor = 'ddnet'): DiscoveredServer
    {
        return new DiscoveredServer($addresses, $name, 'm', 'g', '0.6.4', 64, 64, $clients, null, $flavor);
    }

    public function test_servers_without_a_shared_address_are_left_separate(): void
    {
        $a = $this->server([new DiscoveredAddress('192.0.2.1', 8303, 6)], [$this->client('x')]);
        $b = $this->server([new DiscoveredAddress('192.0.2.2', 8303, 6)], [$this->client('y')]);

        $merged = (new ServerMerger())->merge([$a, $b]);

        $this->assertCount(2, $merged);
    }

    public function test_servers_sharing_an_address_merge_into_one_with_unioned_addresses(): void
    {
        $first = $this->server(
            [new DiscoveredAddress('192.0.2.1', 8303, 6)],
            [$this->client('alice'), $this->client('bob', 'CLAN')],
            name: 'From Source One',
        );
        $second = $this->server(
            [new DiscoveredAddress('192.0.2.1', 8303, 6), new DiscoveredAddress('192.0.2.1', 8303, 7)],
            [$this->client('bob', 'CLAN'), $this->client('carol')],
            name: 'From Source Two',
        );

        $merged = (new ServerMerger())->merge([$first, $second]);

        $this->assertCount(1, $merged);
        $server = $merged[0];

        $this->assertSame('From Source One', $server->name);

        $this->assertCount(2, $server->addresses);
        $this->assertSame([6, 7], array_map(fn ($a) => $a->protocol, $server->addresses));

        $names = array_map(fn ($c) => $c->name, $server->clients);
        $this->assertSame(['alice', 'bob', 'carol'], $names);
    }

    public function test_same_name_different_clan_are_distinct_clients(): void
    {
        $a = $this->server([new DiscoveredAddress('192.0.2.1', 8303, 6)], [$this->client('bob', 'RED')]);
        $b = $this->server([new DiscoveredAddress('192.0.2.1', 8303, 6)], [$this->client('bob', 'BLUE')]);

        $merged = (new ServerMerger())->merge([$a, $b]);

        $this->assertCount(1, $merged);
        $this->assertCount(2, $merged[0]->clients);
    }

    public function test_combine_coalesces_map_identity_fields_from_primary_and_secondary(): void
    {
        $addr = new DiscoveredAddress('192.0.2.1', 8303, 6);
        $sha256 = str_repeat('a', 64);

        $primary = new DiscoveredServer(
            [$addr], 'S', 'm', 'g', '0.6.4', 64, 64, [], null, 'ddnet',
            mapSha256: $sha256, mapSize: 512, mapCrc: null,
        );
        $secondary = new DiscoveredServer(
            [$addr], 'S', 'm', 'g', '0.6.4', 64, 64, [], null, 'ddnet',
            mapSha256: null, mapSize: null, mapCrc: 'deadbeef',
        );

        $merged = (new ServerMerger())->merge([$primary, $secondary]);

        $this->assertCount(1, $merged);
        $server = $merged[0];

        // primary fills sha256 + size; secondary fills the missing crc via ?? coalesce
        $this->assertSame($sha256, $server->mapSha256);
        $this->assertSame(512, $server->mapSize);
        $this->assertSame('deadbeef', $server->mapCrc);

        // primary wins when both have sha256 (primary's value survives)
        $secondary2 = new DiscoveredServer(
            [$addr], 'S', 'm', 'g', '0.6.4', 64, 64, [], null, 'ddnet',
            mapSha256: str_repeat('b', 64), mapSize: null, mapCrc: null,
        );
        $merged2 = (new ServerMerger())->merge([$primary, $secondary2]);
        $this->assertSame($sha256, $merged2[0]->mapSha256);
    }

    public function test_same_ip_port_different_protocol_merge_into_one_dual_stack_server(): void
    {
        $ddnet = $this->server([new DiscoveredAddress('192.0.2.1', 8303, 6)], [$this->client('alice')], name: 'DDNet', flavor: 'ddnet');
        $seven = $this->server([new DiscoveredAddress('192.0.2.1', 8303, 7)], [$this->client('alice')], name: 'Seven', flavor: 'vanilla_07');

        $merged = (new ServerMerger())->merge([$ddnet, $seven]);

        $this->assertCount(1, $merged);
        $this->assertSame('DDNet', $merged[0]->name);
        $this->assertSame([6, 7], array_map(fn ($a) => $a->protocol, $merged[0]->addresses));
        $this->assertCount(1, $merged[0]->clients);
    }
}
