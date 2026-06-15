<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapAcquisitionAttempt;
use App\Models\MapVersion;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapAcquisitionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_persists_an_attempt_with_relations_and_created_at_only(): void
    {
        $map = Map::create(['name' => 'Kobra']);
        $version = MapVersion::create(['map_id' => $map->id, 'crc' => 'deadbeef']);
        $server = Server::factory()->create();

        $attempt = MapAcquisitionAttempt::create([
            'map_version_id' => $version->id,
            'server_id' => $server->id,
            'source' => 'udp',
            'outcome' => 'failure',
            'reason' => 'timeout',
            'bytes' => null,
        ]);

        $this->assertNotNull($attempt->fresh()->created_at);
        $this->assertNull($attempt->updated_at, 'append-only: no updated_at');
        $this->assertSame('Kobra', $attempt->mapVersion->map->name);
        $this->assertSame($server->id, $attempt->server->id);
    }

    public function test_allows_null_version_and_server(): void
    {
        // legacy fetch(Map) and no-host cases carry null context but are still valid rows
        $attempt = MapAcquisitionAttempt::create([
            'map_version_id' => null,
            'server_id' => null,
            'source' => 'http_mirror',
            'outcome' => 'failure',
            'reason' => 'not_mirrored_404',
        ]);

        $this->assertDatabaseCount('map_acquisition_attempts', 1);
        $this->assertNull($attempt->fresh()->server_id);
    }

    public function test_records_a_success_with_bytes(): void
    {
        $map = Map::create(['name' => 'Aim']);
        $version = MapVersion::create(['map_id' => $map->id, 'sha256' => str_repeat('a', 64)]);

        $attempt = MapAcquisitionAttempt::create([
            'map_version_id' => $version->id,
            'source' => 'http_mirror',
            'outcome' => 'success',
            'bytes' => 4096,
        ]);

        $this->assertSame(4096, $attempt->fresh()->bytes);
        $this->assertSame('success', $attempt->fresh()->outcome);
    }
}
