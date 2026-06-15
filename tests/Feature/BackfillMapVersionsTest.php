<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\Server;
use App\Models\ServerHistory;
use App\Models\Mod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillMapVersionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfills_one_version_per_identified_map_and_links_histories(): void
    {
        $withId = Map::create(['name' => 'FlipLipp30', 'crc' => '3421b577', 'size' => 335351]);
        $keyless = Map::create(['name' => 'mysterymap']);
        $server = Server::factory()->create();
        $mod = Mod::create(['name' => 'Gores']);
        $history = ServerHistory::create([
            'weekday' => 0, 'hour' => 0, 'continuous' => 0,
            'server_id' => $server->id, 'map_id' => $withId->id, 'mod_id' => $mod->id,
        ]);

        $this->artisan('maps:backfill-versions')->assertSuccessful();

        $this->assertDatabaseCount('map_versions', 1); // only the identitied map
        $version = $withId->fresh()->currentVersion;
        $this->assertSame('3421b577', $version->crc);
        $this->assertSame($version->id, $history->fresh()->map_version_id);
        $this->assertNull($keyless->fresh()->currentVersion);

        // idempotent: a second run creates nothing new
        $this->artisan('maps:backfill-versions')->assertSuccessful();
        $this->assertDatabaseCount('map_versions', 1);
    }
}
