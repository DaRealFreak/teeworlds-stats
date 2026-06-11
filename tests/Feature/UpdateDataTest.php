<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UpdateDataTest extends TestCase
{
    use RefreshDatabase;

    private function fakeMaster(): void
    {
        // the DdnetHttpSource tries master1 first; serve it the Phase 2 fixture (3 valid servers)
        Http::fake([
            'master1.ddnet.org/*' => Http::response(
                file_get_contents(base_path('tests/Fixtures/ddnet_servers.json')),
                200,
            ),
        ]);
    }

    public function test_it_ingests_the_ddnet_master_into_logical_servers_and_addresses(): void
    {
        $this->fakeMaster();

        $this->artisan('data:update')->assertSuccessful();

        $this->assertDatabaseCount('servers', 3);
        // dual-stack DDNet server has 2 addresses; the two vanilla servers 1 each
        $this->assertDatabaseCount('server_addresses', 4);
        $this->assertDatabaseHas('servers', ['name' => 'DDNet GER10', 'flavor' => 'ddnet']);
        $this->assertDatabaseHas('servers', ['name' => 'Vanilla 0.7 CTF', 'flavor' => 'vanilla_07']);
        $this->assertDatabaseHas('servers', ['name' => 'Vanilla 0.6 DM', 'flavor' => 'vanilla_06']);

        $this->assertSame([6, 7], Server::where('name', 'DDNet GER10')->first()->protocols());
    }

    public function test_it_persists_players_with_their_cosmetic_snapshot(): void
    {
        $this->fakeMaster();

        $this->artisan('data:update')->assertSuccessful();

        // vin, glow (DDNet GER10) + Bob (Vanilla 0.7 CTF); the scalar client entry is skipped
        $this->assertDatabaseCount('players', 3);

        $vin = Player::where('name', 'vin')->first();
        $this->assertSame('glow_cammo', $vin->skin);
        $this->assertSame(16726016, $vin->color_body);
        $this->assertSame(16745499, $vin->color_feet);
        $this->assertFalse($vin->afk);

        $glow = Player::where('name', 'glow')->first();
        $this->assertTrue($glow->afk);
        $this->assertSame(['name' => 'standard', 'color' => 65408], $glow->skin_parts['body']);
    }

    public function test_it_records_server_and_player_histories_and_opens_sessions(): void
    {
        $this->fakeMaster();

        $this->artisan('data:update')->assertSuccessful();

        // one server_history row per persisted server, one open session per player
        $this->assertDatabaseCount('server_histories', 3);
        $this->assertDatabaseCount('player_histories', 3);
        $this->assertDatabaseCount('player_sessions', 3);
        $this->assertDatabaseHas('player_sessions', ['ended_at' => null]);
    }
}
