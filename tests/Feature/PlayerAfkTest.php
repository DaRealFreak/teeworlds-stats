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
}
