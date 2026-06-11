<?php

namespace Tests\Feature;

use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerFlavorTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_stores_a_flavor_label(): void
    {
        $server = Server::factory()->create(['flavor' => 'ddnet']);

        $this->assertSame('ddnet', $server->fresh()->flavor);
    }

    public function test_two_logical_servers_may_share_an_ip_and_port(): void
    {
        Server::factory()->create(['ip' => '127.0.0.1', 'port' => 8303]);
        // two logical servers (e.g. a sixup server answering both 0.6 and 0.7) may share the same ip/port
        Server::factory()->create(['ip' => '127.0.0.1', 'port' => 8303]);

        $this->assertDatabaseCount('servers', 2);
    }
}
