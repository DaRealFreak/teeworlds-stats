<?php

namespace Tests\Feature;

use App\Console\Commands\UpdateData;
use App\Models\Map;
use App\Models\Server;
use App\Models\ServerHistory;
use App\TwStats\Model\DiscoveredServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapVersionScrapeTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_sighting_records_a_version_and_stamps_history_and_pointer(): void
    {
        $server = Server::factory()->create();
        $sighting = new DiscoveredServer(
            addresses: [],
            name: 'Test Server',
            map: 'FlipLipp30',
            gametype: 'Gores',
            version: '0.6',
            maxClients: 16,
            maxPlayers: 16,
            clients: [],
            location: null,
            flavor: 'vanilla_06',
            mapSha256: null,
            mapSize: 335351,
            mapCrc: '3421b577',
        );

        app(UpdateData::class)->recordServerSighting($server, $sighting);

        $map = Map::where('name', 'FlipLipp30')->firstOrFail();
        $this->assertSame('3421b577', $map->crc, 'maps.* points at the current version');
        $this->assertSame(335351, $map->size);
        $this->assertNull($map->sha256, 'a crc-only sighting must not write sha256');

        $version = $map->currentVersion;
        $this->assertSame('3421b577', $version->crc);

        $history = ServerHistory::where('server_id', $server->id)->firstOrFail();
        $this->assertSame($version->id, $history->map_version_id);
    }
}
