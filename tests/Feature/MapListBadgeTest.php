<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapVersion;
use App\Models\Mod;
use App\Models\Server;
use App\Models\ServerHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MapListBadgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_maps_list_shows_an_availability_badge_for_each_state(): void
    {
        Storage::fake('local');

        // Cached: the current version's .map file is on disk
        $sha = str_repeat('a', 64);
        $cached = Map::create(['name' => 'cached-map', 'sha256' => $sha]);
        MapVersion::create(['map_id' => $cached->id, 'sha256' => $sha, 'first_seen_at' => now(), 'last_seen_at' => now()]);
        Storage::disk('local')->put('maps/cached-map_' . $sha . '.map', 'BYTES');

        // Downloadable: has an identity but no cached file
        Map::create(['name' => 'downloadable-map', 'sha256' => str_repeat('b', 64)]);

        // Unavailable: no identity, no host
        Map::create(['name' => 'unavailable-map']);

        // Downloadable via a recorded host (keyless but hosted)
        $hosted = Map::create(['name' => 'hosted-keyless-map']);
        $mod = Mod::create(['name' => 'dm']);
        $server = Server::factory()->create(['flavor' => 'vanilla_06']);
        ServerHistory::create([
            'weekday' => 1, 'hour' => 12, 'continuous' => true,
            'server_id' => $server->id, 'map_id' => $hosted->id, 'mod_id' => $mod->id, 'minutes' => 5,
        ]);

        $this->get('/maps')
            ->assertOk()
            ->assertSee('Cached')
            ->assertSee('Downloadable')
            ->assertSee('Unavailable')
            ->assertSee('hosted-keyless-map');
    }
}
