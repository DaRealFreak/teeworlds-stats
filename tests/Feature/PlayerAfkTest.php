<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\Mod;
use App\Models\Player;
use App\Models\PlayerSession;
use App\Models\Server;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerAfkTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(Player $player, int $minutes, int $afk, int $tracked): void
    {
        $server = Server::factory()->create();
        $map = Map::create(['name' => 'map' . $minutes . '_' . $afk . '_' . $tracked]);
        $mod = Mod::create(['name' => 'mod' . $minutes . '_' . $afk . '_' . $tracked]);

        PlayerSession::create([
            'player_id' => $player->id,
            'server_id' => $server->id,
            'map_id' => $map->id,
            'mod_id' => $mod->id,
            'minutes' => $minutes,
            'afk_minutes' => $afk,
            'afk_tracked_minutes' => $tracked,
            'started_at' => Carbon::now()->subHour(),
            'last_seen_at' => Carbon::now(),
            'ended_at' => Carbon::now(),
        ]);
    }

    public function test_afk_percent_is_computed_over_tracked_time(): void
    {
        $player = Player::factory()->create();
        // 30 idle of 60 tracked (120 total minutes) → 50%, NOT 25%
        $this->makeSession($player, minutes: 120, afk: 30, tracked: 60);

        $this->assertSame(30, $player->totalAfkMinutes());
        $this->assertSame(60, $player->afkTrackedMinutes());
        $this->assertSame(50, $player->afkPercent());
        $this->assertTrue($player->hasAfkData());
    }

    public function test_player_with_only_untracked_sessions_has_no_afk_data(): void
    {
        $player = Player::factory()->create();
        $this->makeSession($player, minutes: 60, afk: 0, tracked: 0);

        $this->assertSame(0, $player->afkPercent());
        $this->assertFalse($player->hasAfkData());
    }

    public function test_player_page_shows_the_afk_tile_and_session_annotation(): void
    {
        $player = Player::factory()->create(['name' => 'Idle Ivan', 'last_seen' => Carbon::now()]);
        $server = Server::factory()->create(['name' => 'Idle Server']);
        $map = Map::create(['name' => 'idlemap']);
        $mod = Mod::create(['name' => 'idlemod']);

        // 30 idle of 120 tracked → 25%
        PlayerSession::create([
            'player_id' => $player->id,
            'server_id' => $server->id,
            'map_id' => $map->id,
            'mod_id' => $mod->id,
            'minutes' => 120,
            'afk_minutes' => 30,
            'afk_tracked_minutes' => 120,
            'started_at' => Carbon::now()->subDay()->setTime(20, 0),
            'last_seen_at' => Carbon::now()->subDay()->setTime(22, 0),
            'ended_at' => Carbon::now()->subDay()->setTime(22, 0),
        ]);

        $response = $this->get('/tee/' . urlencode('Idle Ivan'));

        $response->assertOk();
        $response->assertSee('on servers tracking idle status');
        $response->assertSee('25%');
        // per-session annotation (lowercase "afk" only appears on the session row)
        $response->assertSee('afk', false);
    }

    public function test_player_page_hides_the_afk_tile_without_tracked_data(): void
    {
        $player = Player::factory()->create(['name' => 'No Idle Ned', 'last_seen' => Carbon::now()]);
        $server = Server::factory()->create();
        $map = Map::create(['name' => 'plainmap']);
        $mod = Mod::create(['name' => 'plainmod']);

        PlayerSession::create([
            'player_id' => $player->id,
            'server_id' => $server->id,
            'map_id' => $map->id,
            'mod_id' => $mod->id,
            'minutes' => 60,
            'afk_minutes' => 0,
            'afk_tracked_minutes' => 0,
            'started_at' => Carbon::now()->subHour(),
            'last_seen_at' => Carbon::now(),
            'ended_at' => Carbon::now(),
        ]);

        $response = $this->get('/tee/' . urlencode('No Idle Ned'));

        $response->assertOk();
        $response->assertDontSee('on servers tracking idle status');
    }
}
