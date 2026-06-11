<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\Mod;
use App\Models\Player;
use App\Models\PlayerHistory;
use App\Models\PlayerSession;
use App\Models\Server;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_overview_renders_with_sessions_and_country_flag(): void
    {
        $player = Player::factory()->create([
            'name' => 'Heatmap Hero',
            'country' => 'Germany',
            'last_seen' => Carbon::now(),
        ]);
        $server = Server::factory()->create(['name' => 'Test DDNet']);
        $map = Map::create(['name' => 'Dustycave']);
        $mod = Mod::create(['name' => 'DDraceNetwork']);

        // play history drives the totals, heatmap, top lists and busiest tiles
        foreach ([[3, 20, 120], [3, 21, 60], [5, 18, 30]] as [$weekday, $hour, $minutes]) {
            PlayerHistory::create([
                'weekday' => $weekday,
                'hour' => $hour,
                'continuous' => true,
                'server_id' => $server->id,
                'player_id' => $player->id,
                'map_id' => $map->id,
                'mod_id' => $mod->id,
                'minutes' => $minutes,
            ]);
        }

        // one finished session and one still-open (live) session
        PlayerSession::create([
            'player_id' => $player->id,
            'server_id' => $server->id,
            'map_id' => $map->id,
            'mod_id' => $mod->id,
            'minutes' => 90,
            'started_at' => Carbon::now()->subDay()->setTime(20, 0),
            'last_seen_at' => Carbon::now()->subDay()->setTime(21, 30),
            'ended_at' => Carbon::now()->subDay()->setTime(21, 30),
        ]);
        PlayerSession::create([
            'player_id' => $player->id,
            'server_id' => $server->id,
            'map_id' => $map->id,
            'mod_id' => $mod->id,
            'minutes' => 10,
            'started_at' => Carbon::now()->subMinutes(10),
            'last_seen_at' => Carbon::now(),
            'ended_at' => null,
        ]);

        $response = $this->get('/tee/' . urlencode('Heatmap Hero'));

        $response->assertOk();
        $response->assertSee('Heatmap Hero');
        // identity header: online status + the German flag class from flag-icons
        $response->assertSee('Online now');
        $response->assertSee('fi-de', false);
        // the new sections are present
        $response->assertSee('When Heatmap Hero plays');
        $response->assertSee('Recent sessions');
        $response->assertSee('Favorite servers');
        $response->assertSee('Most played maps');
        // session + map/server data renders
        $response->assertSee('Test DDNet');
        $response->assertSee('Dustycave');
    }

    public function test_player_overview_renders_without_any_sessions(): void
    {
        $player = Player::factory()->create([
            'name' => 'Fresh Tee',
            'country' => 'none',
            'last_seen' => Carbon::now()->subDays(3),
        ]);
        $server = Server::factory()->create();
        $map = Map::create(['name' => 'Linear']);
        $mod = Mod::create(['name' => 'gores']);
        PlayerHistory::create([
            'weekday' => 1,
            'hour' => 12,
            'continuous' => false,
            'server_id' => $server->id,
            'player_id' => $player->id,
            'map_id' => $map->id,
            'mod_id' => $mod->id,
            'minutes' => 20,
        ]);

        $response = $this->get('/tee/' . urlencode('Fresh Tee'));

        $response->assertOk();
        $response->assertSee('Fresh Tee');
        // no sessions yet → empty-state copy, and offline status
        $response->assertSee('No sessions recorded yet', false);
        $response->assertSee('Last seen');
    }
}
