<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapAcquisitionAttempt;
use App\Models\MapVersion;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_authenticated_user_sees_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin')
            ->assertOk()
            ->assertSee('Map Archive');
    }

    public function test_dashboard_shows_failing_servers_and_sections(): void
    {
        $map = Map::create(['name' => 'Kobra']);
        $version = MapVersion::create(['map_id' => $map->id, 'crc' => 'deadbeef']);
        $server = Server::factory()->create(['name' => 'BadHost']);
        MapAcquisitionAttempt::create(['map_version_id' => $version->id, 'server_id' => $server->id, 'source' => 'udp', 'outcome' => 'failure', 'reason' => 'timeout']);

        $this->actingAs(\App\Models\User::factory()->create())
            ->get('/admin')
            ->assertOk()
            ->assertSee('Failing servers')
            ->assertSee('Downloads over time')
            ->assertSee('BadHost')
            ->assertSee('downloadsChart');
    }
}
