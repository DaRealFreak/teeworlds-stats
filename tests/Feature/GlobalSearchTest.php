<?php

namespace Tests\Feature;

use App\Models\Clan;
use App\Models\Map;
use App\Models\Mod;
use App\Models\Player;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The navbar global search returns matches grouped by entity type, each carrying a
     * ready-to-use detail URL built server-side (the server owns the route patterns).
     */
    public function test_global_search_groups_matches_by_type_with_urls(): void
    {
        Player::create(['name' => 'nameless tee', 'country' => 'DE']);
        Clan::factory()->create(['name' => 'nameless crew']);
        $server = Server::factory()->create(['name' => 'nameless server']);
        Map::create(['name' => 'namelessmap']);
        Mod::create(['name' => 'namelessmod']);

        $response = $this->getJson('/search/global?term=nameless');

        $response->assertOk();
        $response->assertJsonStructure([
            'players' => [['name', 'url']],
            'clans' => [['name', 'url']],
            'servers' => [['name', 'id', 'url']],
            'maps' => [['name', 'url']],
            'mods' => [['name', 'url']],
        ]);

        $response->assertJsonPath('players.0.name', 'nameless tee');
        $response->assertJsonPath('players.0.url', url('tee', urlencode('nameless tee')));
        $response->assertJsonPath(
            'servers.0.url',
            url('server', [urlencode($server->id), urlencode($server->name)])
        );
    }

    /**
     * Mirrors the navbar JS guard: terms shorter than 2 chars do no work and return
     * empty groups (not a 404 / not an error).
     */
    public function test_global_search_returns_empty_groups_for_short_terms(): void
    {
        Player::create(['name' => 'nameless tee', 'country' => 'DE']);

        $response = $this->getJson('/search/global?term=n');

        $response->assertOk();
        $response->assertExactJson([
            'players' => [],
            'clans' => [],
            'servers' => [],
            'maps' => [],
            'mods' => [],
        ]);
    }
}
