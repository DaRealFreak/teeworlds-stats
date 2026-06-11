<?php

namespace Tests\Feature;

use App\Models\Clan;
use App\Models\DailySummary;
use App\Models\Map;
use App\Models\Mod;
use App\Models\Player;
use App\Models\PlayerHistory;
use App\Models\Server;
use App\Models\ServerHistory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RouteSmokeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Insert the minimum one-row-per-entity fixture set using direct Model::create
     * calls (no factory() helper) so the test is portable to Laravel 13.
     *
     * Includes PlayerHistory, ServerHistory, and DailySummary rows so that the
     * /tees, /servers, and /general routes render without null-dereference errors:
     *   - /tees  uses totalHoursOnline() (needs playRecords grouped by player_id),
     *             chartPlayedMods() and chartPlayedMaps() (need non-empty results).
     *   - /servers uses totalHoursOnline() (needs serverRecords grouped by server_id),
     *               chartPlayedMaps() and chartPlayedMods() (non-empty results).
     *   - /general uses DailySummary::firstOrCreate(['date' => today()]) which
     *               would violate NOT NULL on 6 unsignedInteger columns if no row
     *               exists and no defaults are set.
     */
    protected function seedMinimalData(): void
    {
        $server = Server::create([
            'name'    => 'Test Server',
            'version' => '0.7.5',
            'ip'      => '127.0.0.1',
            'port'    => 8303,
        ]);

        $player = Player::create([
            'name'    => 'TestTee',
            'country' => 'DE',
        ]);

        Clan::create([
            'name'         => 'TestClan',
            'introduction' => 'A test clan.',
            'website'      => 'https://example.com',
        ]);

        $mod = Mod::create([
            'name' => 'vanilla',
        ]);

        $map = Map::create([
            'name' => 'dm1',
        ]);

        // PlayerHistory: satisfies Player::totalHoursOnline(), chartPlayedMods(),
        // and chartPlayedMaps() which all require at least one history row linked
        // to the player with valid map_id and mod_id.
        PlayerHistory::create([
            'server_id'  => $server->id,
            'player_id'  => $player->id,
            'map_id'     => $map->id,
            'mod_id'     => $mod->id,
            'weekday'    => 1,
            'hour'       => 12,
            'continuous' => 1,
            'minutes'    => 60,
        ]);

        // ServerHistory: satisfies Server::totalHoursOnline(), chartPlayedMaps(),
        // and chartPlayedMods() which all require at least one history row linked
        // to the server with valid map_id and mod_id.
        ServerHistory::create([
            'server_id'  => $server->id,
            'map_id'     => $map->id,
            'mod_id'     => $mod->id,
            'weekday'    => 1,
            'hour'       => 12,
            'continuous' => 1,
            'minutes'    => 60,
        ]);

        // DailySummary for today: DailySummary::firstOrCreate(['date' => Carbon::today()])
        // in MainController@general would violate NOT NULL on the 6 unsignedInteger
        // columns (players_online_peak, players_online, clans_online_peak,
        // clans_online, servers_online_peak, servers_online) because there are no
        // column-level defaults in the migration.  The date value must match the
        // Carbon::today() object the controller passes verbatim so that firstOrCreate
        // finds this row rather than attempting a new INSERT with only 'date' set.
        DailySummary::create([
            'date'                => Carbon::today(),
            'players_online_peak' => 1,
            'players_online'      => 1,
            'clans_online_peak'   => 0,
            'clans_online'        => 0,
            'servers_online_peak' => 1,
            'servers_online'      => 1,
        ]);
    }

    /**
     * Routes that render correctly with the seeded fixture data.
     */
    #[DataProvider('publicRouteProvider')]
    public function test_public_routes_return_ok(string $uri): void
    {
        $this->seedMinimalData();
        $response = $this->get($uri);
        $response->assertStatus(200);
    }

    public static function publicRouteProvider(): array
    {
        return [
            'home'     => ['/'],
            'about'    => ['/about'],
            'search'   => ['/search'],
            'clans'    => ['/clans'],
            'mods'     => ['/mods'],
            'maps'     => ['/maps'],
            'login'    => ['/login'],
            'register' => ['/register'],
            'general'  => ['/general'],
            'tees'     => ['/tees'],
            'servers'  => ['/servers'],
            'serverbrowser' => ['/serverbrowser'],
        ];
    }
}
