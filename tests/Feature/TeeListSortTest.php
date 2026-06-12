<?php

namespace Tests\Feature;

use App\Models\Clan;
use App\Models\Map;
use App\Models\Mod;
use App\Models\Player;
use App\Models\PlayerClanHistory;
use App\Models\PlayerHistory;
use App\Models\PlayerSession;
use App\Models\Server;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeeListSortTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Seed a player with a play history (so the Mod/Map cells render), and optionally a session
     * carrying AFK counters and a current clan.
     */
    private function seedPlayer(string $name, int $playedMinutes = 10, ?int $afk = null, ?int $afkTracked = null, ?string $clan = null): Player
    {
        $player = Player::factory()->create(['name' => $name, 'last_seen' => Carbon::now()]);
        $server = Server::factory()->create();
        $map = Map::create(['name' => 'map_' . $name]);
        $mod = Mod::create(['name' => 'mod_' . $name]);

        PlayerHistory::create([
            'weekday' => 1, 'hour' => 12, 'continuous' => true,
            'server_id' => $server->id, 'player_id' => $player->id,
            'map_id' => $map->id, 'mod_id' => $mod->id, 'minutes' => $playedMinutes,
        ]);

        if ($afkTracked !== null) {
            PlayerSession::create([
                'player_id' => $player->id, 'server_id' => $server->id, 'map_id' => $map->id, 'mod_id' => $mod->id,
                'minutes' => $playedMinutes, 'afk_minutes' => $afk ?? 0, 'afk_tracked_minutes' => $afkTracked,
                'started_at' => Carbon::now()->subHour(), 'last_seen_at' => Carbon::now(), 'ended_at' => Carbon::now(),
            ]);
        }

        if ($clan !== null) {
            $clanModel = Clan::factory()->create(['name' => $clan]);
            PlayerClanHistory::create(['player_id' => $player->id, 'clan_id' => $clanModel->id, 'left_at' => null]);
        }

        return $player;
    }

    public function test_default_sort_is_by_name(): void
    {
        $this->seedPlayer('Zeta');
        $this->seedPlayer('Alpha');

        $this->get('/tees')->assertOk()->assertSeeInOrder(['Alpha', 'Zeta']);
    }

    public function test_sort_by_afk_orders_by_idle_share_and_keeps_no_data_last(): void
    {
        $this->seedPlayer('IdleHigh', afk: 80, afkTracked: 100); // 80%
        $this->seedPlayer('IdleLow', afk: 10, afkTracked: 100);  // 10%
        $this->seedPlayer('NoData');                              // no session → null ratio

        $this->get('/tees?sort=afk&dir=desc')->assertOk()->assertSeeInOrder(['IdleHigh', 'IdleLow', 'NoData']);
        $this->get('/tees?sort=afk&dir=asc')->assertOk()->assertSeeInOrder(['IdleLow', 'IdleHigh', 'NoData']);
    }

    public function test_sort_by_played_time(): void
    {
        $this->seedPlayer('Grinder', playedMinutes: 600);
        $this->seedPlayer('Casual', playedMinutes: 20);

        $this->get('/tees?sort=played&dir=desc')->assertOk()->assertSeeInOrder(['Grinder', 'Casual']);
        $this->get('/tees?sort=played&dir=asc')->assertOk()->assertSeeInOrder(['Casual', 'Grinder']);
    }

    public function test_sort_by_clan_name(): void
    {
        // names deliberately inverse to clan order, so a pass proves clan (not name) sorting
        $this->seedPlayer('Zeta', clan: 'AAA');
        $this->seedPlayer('Alpha', clan: 'ZZZ');
        $this->seedPlayer('NoClan'); // clanless → forced last in both directions

        $this->get('/tees?sort=clan&dir=asc')->assertOk()->assertSeeInOrder(['Zeta', 'Alpha', 'NoClan']);
        $this->get('/tees?sort=clan&dir=desc')->assertOk()->assertSeeInOrder(['Alpha', 'Zeta', 'NoClan']);
    }

    public function test_invalid_sort_falls_back_to_name_without_error(): void
    {
        $this->seedPlayer('Zeta');
        $this->seedPlayer('Alpha');

        $this->get('/tees?sort=garbage&dir=sideways')->assertOk()->assertSeeInOrder(['Alpha', 'Zeta']);
    }

    public function test_afk_column_renders_percentage_and_dash(): void
    {
        $this->seedPlayer('IdleIvan', afk: 40, afkTracked: 80); // 50%
        $this->seedPlayer('FreshNed');                          // no session → no AFK data

        $response = $this->get('/tees');

        $response->assertOk();
        $response->assertSee('50%');
        $response->assertSee('—', false); // FreshNed's AFK cell (he has history, so Mod/Map are not dashes)
    }

    public function test_active_sort_header_shows_a_caret_and_sort_links(): void
    {
        $this->seedPlayer('Solo');

        $response = $this->get('/tees?sort=afk&dir=desc');

        $response->assertOk();
        $response->assertSee('fa-caret-down', false); // active AFK header, descending
        $response->assertSee('sort=afk', false);      // header links carry the sort param
    }
}
