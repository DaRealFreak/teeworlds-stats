<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\Mod;
use App\Models\Server;
use App\Models\ServerHistory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveServerBrowserTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_server_history_returns_latest_map_and_mod(): void
    {
        $server = Server::create([
            'name' => 'Relation Server', 'version' => '0.7.5', 'ip' => '127.0.0.1', 'port' => 8303,
        ]);

        $oldMap = Map::create(['name' => 'dm1']);
        $oldMod = Mod::create(['name' => 'dm']);
        $newMap = Map::create(['name' => 'ctf5']);
        $newMod = Mod::create(['name' => 'ctf']);

        $old = ServerHistory::create([
            'server_id' => $server->id, 'map_id' => $oldMap->id, 'mod_id' => $oldMod->id,
            'weekday' => 1, 'hour' => 12, 'continuous' => 1, 'minutes' => 60,
        ]);
        // backdate the old row's updated_at without the model touching it
        ServerHistory::where('id', $old->id)->update(['updated_at' => Carbon::now()->subHour()]);

        ServerHistory::create([
            'server_id' => $server->id, 'map_id' => $newMap->id, 'mod_id' => $newMod->id,
            'weekday' => 1, 'hour' => 13, 'continuous' => 1, 'minutes' => 60,
        ]);

        $history = $server->currentServerHistory;

        $this->assertNotNull($history);
        $this->assertSame('ctf5', $history->map->name);
        $this->assertSame('ctf', $history->mod->name);
    }
}
