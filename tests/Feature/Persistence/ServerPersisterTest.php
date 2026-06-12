<?php

namespace Tests\Feature\Persistence;

use App\Models\Server;
use App\Models\ServerAddress;
use App\TwStats\Discovery\DiscoveredAddress;
use App\TwStats\Discovery\DiscoveredServer;
use App\TwStats\Persistence\ServerPersister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerPersisterTest extends TestCase
{
    use RefreshDatabase;

    private function server(array $addresses, string $name = 'GER10', string $flavor = 'ddnet'): DiscoveredServer
    {
        return new DiscoveredServer($addresses, $name, 'Multeasymap', 'DDraceNetwork', '0.6.4, 19.1', 64, 64, [], 'eu', $flavor);
    }

    public function test_creates_a_logical_server_with_its_addresses_flavor_and_canonical_pointer(): void
    {
        $discovered = $this->server([
            new DiscoveredAddress('192.0.2.10', 8303, 6),
            new DiscoveredAddress('192.0.2.10', 8303, 7),
        ]);

        $server = (new ServerPersister())->persist($discovered);

        $this->assertDatabaseCount('servers', 1);
        $this->assertSame('GER10', $server->name);
        $this->assertSame('ddnet', $server->flavor);
        $this->assertSame('192.0.2.10', $server->ip);
        $this->assertSame(8303, $server->port);

        $this->assertSame([6, 7], $server->fresh()->protocols());
        $this->assertSame(6, $server->fresh()->canonicalAddress->protocol);
        $this->assertSame(1, ServerAddress::where('is_canonical', true)->where('server_id', $server->id)->count());
    }

    public function test_reuses_the_same_logical_server_on_a_later_cycle(): void
    {
        $persister = new ServerPersister();
        $first = $persister->persist($this->server([new DiscoveredAddress('192.0.2.10', 8303, 6)]));
        $second = $persister->persist($this->server([new DiscoveredAddress('192.0.2.10', 8303, 6)], name: 'GER10 renamed'));

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('servers', 1);
        $this->assertDatabaseCount('server_addresses', 1);
        $this->assertSame('GER10 renamed', $second->name);
    }

    public function test_matches_an_existing_server_by_any_shared_address(): void
    {
        $persister = new ServerPersister();
        $created = $persister->persist($this->server([
            new DiscoveredAddress('192.0.2.10', 8303, 6),
            new DiscoveredAddress('192.0.2.10', 8303, 7),
        ]));

        $matched = $persister->persist($this->server([new DiscoveredAddress('192.0.2.10', 8303, 7)], name: 'still GER10'));

        $this->assertSame($created->id, $matched->id);
        $this->assertDatabaseCount('servers', 1);
    }

    public function test_canonical_address_follows_the_first_address_when_the_order_flips(): void
    {
        $persister = new ServerPersister();
        $persister->persist($this->server([
            new DiscoveredAddress('192.0.2.10', 8303, 6),
            new DiscoveredAddress('192.0.2.10', 8303, 7),
        ]));

        // the next cycle reports the 0.7 endpoint first
        $server = $persister->persist($this->server([
            new DiscoveredAddress('192.0.2.10', 8303, 7),
            new DiscoveredAddress('192.0.2.10', 8303, 6),
        ]));

        $this->assertSame(7, $server->fresh()->canonicalAddress->protocol);
        $this->assertSame(1, ServerAddress::where('server_id', $server->id)->where('is_canonical', true)->count());
    }

    public function test_rejects_a_server_with_no_addresses(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ServerPersister())->persist($this->server([]));
    }

    public function test_a_later_protocol_resolves_to_the_same_server_by_ip_and_port(): void
    {
        $persister = new ServerPersister();
        $created = $persister->persist($this->server([new DiscoveredAddress('192.0.2.10', 8303, 6)]));
        $matched = $persister->persist($this->server([new DiscoveredAddress('192.0.2.10', 8303, 7)], name: 'sixup'));

        $this->assertSame($created->id, $matched->id);
        $this->assertDatabaseCount('servers', 1);
        $this->assertSame([6, 7], $matched->fresh()->protocols());
    }
}
