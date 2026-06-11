<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\ServerAddress;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_has_many_addresses_and_exposes_its_protocol_set(): void
    {
        $server = Server::factory()->create();

        ServerAddress::create([
            'server_id'    => $server->id,
            'ip'           => '127.0.0.1',
            'port'         => 8303,
            'protocol'     => 6,
            'is_canonical' => true,
        ]);
        ServerAddress::create([
            'server_id'    => $server->id,
            'ip'           => '127.0.0.1',
            'port'         => 8303,
            'protocol'     => 7,
            'is_canonical' => false,
        ]);

        $server->refresh();

        $this->assertCount(2, $server->addresses);
        $this->assertSame([6, 7], $server->protocols());
        $this->assertSame(6, $server->canonicalAddress->protocol);
        $this->assertTrue($server->canonicalAddress->is_canonical);
    }

    public function test_same_ip_port_protocol_cannot_be_inserted_twice(): void
    {
        $server = Server::factory()->create();

        ServerAddress::create([
            'server_id' => $server->id,
            'ip'        => '127.0.0.1',
            'port'      => 8303,
            'protocol'  => 6,
        ]);

        $this->expectException(QueryException::class);

        ServerAddress::create([
            'server_id' => $server->id,
            'ip'        => '127.0.0.1',
            'port'      => 8303,
            'protocol'  => 6,
        ]);
    }

    public function test_deleting_a_server_cascades_to_its_addresses(): void
    {
        $server = Server::factory()->create();
        $address = ServerAddress::factory()->for($server)->create();

        $server->delete();

        $this->assertDatabaseMissing('server_addresses', ['id' => $address->id]);
    }
}
