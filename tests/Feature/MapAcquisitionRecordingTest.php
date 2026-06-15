<?php

namespace Tests\Feature;

use App\Events\MapAcquisitionAttempted;
use App\Models\Map;
use App\Models\MapAcquisitionAttempt;
use App\Models\MapVersion;
use App\Service\DdnetHttpMapSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MapAcquisitionRecordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatching_the_event_writes_a_row(): void
    {
        $map = Map::create(['name' => 'Aim']);
        $version = MapVersion::create(['map_id' => $map->id, 'sha256' => str_repeat('a', 64)]);

        // auto-discovered listener persists the row (Laravel 11+ registers app/Listeners by handle() type-hint)
        MapAcquisitionAttempted::dispatch($version->id, null, 'http_mirror', 'success', null, 4096);

        $this->assertDatabaseHas('map_acquisition_attempts', [
            'map_version_id' => $version->id,
            'server_id' => null,
            'source' => 'http_mirror',
            'outcome' => 'success',
            'bytes' => 4096,
        ]);
    }

    public function test_listener_swallows_a_write_failure(): void
    {
        // a bogus server_id violates the FK; the listener must catch it so a download is never broken
        $listener = new \App\Listeners\RecordMapAcquisitionAttempt();
        $listener->handle(new MapAcquisitionAttempted(null, 999999, 'udp', 'failure', 'timeout', null));

        $this->assertDatabaseCount('map_acquisition_attempts', 0);
    }

    public function test_http_mirror_success_is_recorded(): void
    {
        $bytes = 'DATA' . str_repeat("\x00", 64);
        $sha = hash('sha256', $bytes);
        $map = Map::create(['name' => 'Aim']);
        $version = MapVersion::create(['map_id' => $map->id, 'sha256' => $sha, 'size' => strlen($bytes)]);

        Http::fake(['maps.ddnet.org/*' => Http::response($bytes, 200)]);

        (new DdnetHttpMapSource())->fetchVersion($version);

        $this->assertDatabaseHas('map_acquisition_attempts', [
            'map_version_id' => $version->id, 'server_id' => null,
            'source' => 'http_mirror', 'outcome' => 'success', 'bytes' => strlen($bytes),
        ]);
    }

    public function test_http_mirror_404_is_recorded_as_failure(): void
    {
        $map = Map::create(['name' => 'Aim']);
        $version = MapVersion::create(['map_id' => $map->id, 'sha256' => str_repeat('b', 64)]);

        Http::fake(['maps.ddnet.org/*' => Http::response('nope', 404)]);

        (new DdnetHttpMapSource())->fetchVersion($version);

        $this->assertDatabaseHas('map_acquisition_attempts', [
            'map_version_id' => $version->id, 'source' => 'http_mirror',
            'outcome' => 'failure', 'reason' => 'not_mirrored_404',
        ]);
    }
}
