<?php

namespace Tests\Feature;

use App\Models\Clan;
use App\Models\Map;
use App\Models\Mod;
use App\Models\Player;
use App\Models\PlayerClanHistory;
use App\Models\PlayerHistory;
use App\Models\Server;
use App\Models\ServerHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapModDetailPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * seed one map, one mod, and a player (in a clan) + server with both history kinds,
     * then return [map, mod] for the page under test.
     *
     * @return array{0: Map, 1: Mod}
     */
    private function seedPlayData(): array
    {
        $map    = Map::create(['name' => 'RankedMap']);
        $mod    = Mod::create(['name' => 'RankedMod']);
        $server = Server::factory()->create(['name' => 'RankedServer']);
        $player = Player::factory()->create(['name' => 'RankedTee']);
        $clan   = Clan::factory()->create(['name' => 'RankedClan']);

        PlayerClanHistory::create(['player_id' => $player->id, 'clan_id' => $clan->id, 'left_at' => null]);

        PlayerHistory::create([
            'weekday' => 1, 'hour' => 20, 'continuous' => true,
            'server_id' => $server->id, 'player_id' => $player->id,
            'map_id' => $map->id, 'mod_id' => $mod->id, 'minutes' => 120,
        ]);

        ServerHistory::create([
            'weekday' => 1, 'hour' => 20, 'continuous' => true,
            'server_id' => $server->id, 'map_id' => $map->id,
            'mod_id' => $mod->id, 'minutes' => 300,
        ]);

        return [$map, $mod];
    }

    public function test_mod_detail_page_renders_stats_and_rankings(): void
    {
        $this->seedPlayData();

        $response = $this->get('/mod/' . urlencode('RankedMod') . '/');

        $response->assertStatus(200);
        $response->assertSee('Mod: RankedMod');
        $response->assertSee('Top players');
        $response->assertSee('Top clans');
        $response->assertSee('Top servers');
        $response->assertSee('RankedTee');
        $response->assertSee('RankedClan');
        $response->assertSee('RankedServer');
    }

    public function test_map_detail_page_renders_stats_and_rankings(): void
    {
        $this->seedPlayData();

        $response = $this->get('/map/' . urlencode('RankedMap') . '/');

        $response->assertStatus(200);
        $response->assertSee('Map: RankedMap');
        $response->assertSee('Top players');
        $response->assertSee('RankedTee');
        $response->assertSee('RankedClan');
        $response->assertSee('RankedServer');
    }

    public function test_empty_map_detail_page_still_renders(): void
    {
        Map::create(['name' => 'LonelyMap']);

        $response = $this->get('/map/' . urlencode('LonelyMap') . '/');

        $response->assertStatus(200);
        $response->assertSee('Map: LonelyMap');
        $response->assertSee('No player activity recorded yet.');
    }
}
