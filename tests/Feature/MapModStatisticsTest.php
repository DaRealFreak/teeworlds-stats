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

class MapModStatisticsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * create a player_histories row, filling every NOT NULL column.
     */
    private function playRecord(array $overrides): PlayerHistory
    {
        return PlayerHistory::create(array_merge([
            'weekday'    => 1,
            'hour'       => 20,
            'continuous' => true,
            'minutes'    => 60,
        ], $overrides));
    }

    public function test_total_player_minutes_sums_the_maps_records(): void
    {
        $map = Map::create(['name' => 'ctf1']);
        $mod = Mod::create(['name' => 'ctf']);
        $server = Server::factory()->create();
        $player = Player::factory()->create();

        $this->playRecord(['server_id' => $server->id, 'player_id' => $player->id, 'map_id' => $map->id, 'mod_id' => $mod->id, 'minutes' => 40]);
        $this->playRecord(['server_id' => $server->id, 'player_id' => $player->id, 'map_id' => $map->id, 'mod_id' => $mod->id, 'minutes' => 75]);

        $this->assertSame(115, $map->totalPlayerMinutes());
    }

    public function test_mod_aggregates_variant_records_via_mod_original_id(): void
    {
        $parent  = Mod::create(['name' => 'race']);
        $variant = Mod::create(['name' => 'fastrace']);
        $map     = Map::create(['name' => 'dm1']);
        $server  = Server::factory()->create();
        $player  = Player::factory()->create();

        // a direct record on the parent mod
        $this->playRecord(['server_id' => $server->id, 'player_id' => $player->id, 'map_id' => $map->id, 'mod_id' => $parent->id, 'minutes' => 70]);
        // a record on the variant that points back at the parent via mod_original_id
        $this->playRecord(['server_id' => $server->id, 'player_id' => $player->id, 'map_id' => $map->id, 'mod_id' => $variant->id, 'mod_original_id' => $parent->id, 'minutes' => 50]);

        $this->assertSame(120, $parent->totalPlayerMinutes());
    }

    public function test_top_players_orders_by_summed_minutes(): void
    {
        $mod    = Mod::create(['name' => 'dm']);
        $map    = Map::create(['name' => 'dm2']);
        $server = Server::factory()->create();
        $alpha  = Player::factory()->create(['name' => 'Alpha']);
        $bravo  = Player::factory()->create(['name' => 'Bravo']);

        $this->playRecord(['server_id' => $server->id, 'player_id' => $alpha->id, 'map_id' => $map->id, 'mod_id' => $mod->id, 'minutes' => 30]);
        $this->playRecord(['server_id' => $server->id, 'player_id' => $bravo->id, 'map_id' => $map->id, 'mod_id' => $mod->id, 'minutes' => 90]);

        $top = $mod->topPlayers(10);

        $this->assertSame($bravo->id, (int) $top->first()->player_id);
        $this->assertSame(90, (int) $top->first()->sum_minutes);
        $this->assertSame('Bravo', $top->first()->player->name);
    }

    public function test_top_clans_excludes_players_who_left(): void
    {
        $mod     = Mod::create(['name' => 'ctf']);
        $map     = Map::create(['name' => 'ctf2']);
        $server  = Server::factory()->create();
        $stayer  = Player::factory()->create();
        $leaver  = Player::factory()->create();
        $current = Clan::factory()->create(['name' => 'CurrentClan']);
        $former  = Clan::factory()->create(['name' => 'FormerClan']);

        PlayerClanHistory::create(['player_id' => $stayer->id, 'clan_id' => $current->id, 'left_at' => null]);
        PlayerClanHistory::create(['player_id' => $leaver->id, 'clan_id' => $former->id, 'left_at' => now()]);

        $this->playRecord(['server_id' => $server->id, 'player_id' => $stayer->id, 'map_id' => $map->id, 'mod_id' => $mod->id, 'minutes' => 80]);
        $this->playRecord(['server_id' => $server->id, 'player_id' => $leaver->id, 'map_id' => $map->id, 'mod_id' => $mod->id, 'minutes' => 200]);

        $topClans = $mod->topClans(10);

        $this->assertCount(1, $topClans);
        $this->assertSame($current->id, $topClans->first()['clan']->id);
        $this->assertSame(80, $topClans->first()['minutes']);
    }
}
