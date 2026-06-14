<?php

namespace Tests\Feature;

use App\Models\Map;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MapListBadgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_maps_list_shows_a_badge_for_each_archive_state(): void
    {
        Storage::fake('local');

        $downloadedSha = str_repeat('a', 64);
        Map::create(['name' => 'downloaded-map', 'sha256' => $downloadedSha]);
        Storage::disk('local')->put('maps/downloaded-map_' . $downloadedSha . '.map', 'BYTES');

        Map::create(['name' => 'queued-map', 'sha256' => str_repeat('b', 64)]);
        Map::create(['name' => 'retrying-map', 'sha256' => str_repeat('c', 64), 'last_archive_attempt_at' => now()]);
        Map::create(['name' => 'unavailable-map']);

        $hosted = Map::create(['name' => 'hosted-keyless-map']);
        $mod    = \App\Models\Mod::create(['name' => 'dm']);
        $server = \App\Models\Server::factory()->create(['flavor' => 'vanilla_06']);
        \App\Models\ServerHistory::create([
            'weekday' => 1, 'hour' => 12, 'continuous' => true,
            'server_id' => $server->id, 'map_id' => $hosted->id, 'mod_id' => $mod->id, 'minutes' => 5,
        ]);

        $this->get('/maps')
            ->assertOk()
            ->assertSee('Downloaded')
            ->assertSee('Queued')
            ->assertSee('Retrying')
            ->assertSee('Unavailable')
            // the keyless-but-hosted map renders on the list (exercises the withCount eager path);
            // its Queued status is asserted directly in MapArchiverTest
            ->assertSee('hosted-keyless-map');
    }
}
