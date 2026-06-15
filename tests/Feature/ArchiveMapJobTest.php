<?php

namespace Tests\Feature;

use App\Jobs\ArchiveMap;
use App\Models\Map;
use App\Service\MapArchiver;
use App\Service\MapFileStore;
use App\Service\MapFileUnavailable;
use App\Service\MapSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArchiveMapJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_unique_id_is_the_map_id(): void
    {
        $map = Map::create(['name' => 'x', 'sha256' => str_repeat('a', 64)]);

        $this->assertSame((string) $map->id, (new ArchiveMap($map))->uniqueId());
    }

    public function test_handle_downloads_the_map(): void
    {
        Storage::fake('local');
        $body = 'JOBBYTES';
        $sha = hash('sha256', $body);
        $map = Map::create(['name' => 'job map', 'sha256' => $sha, 'size' => strlen($body)]);

        $source = new class($body) implements MapSource {
            public function __construct(private string $body) {}
            public function fetch(Map $map): ?string { return $this->body; }
            public function fetchVersion(\App\Models\MapVersion $version): ?string { return $this->body; }
        };
        $store = new MapFileStore([$source]);

        (new ArchiveMap($map))->handle(new MapArchiver($store));

        $this->assertTrue($store->isCached($map->fresh()));
        $this->assertNotNull($map->fresh()->last_archive_attempt_at);
    }

    public function test_handle_throws_and_parks_map_when_no_source_serves(): void
    {
        Storage::fake('local');
        $map = Map::create(['name' => 'ghost', 'sha256' => str_repeat('a', 64)]);
        $archiver = new MapArchiver(new MapFileStore([]));

        try {
            (new ArchiveMap($map))->handle($archiver);
            $this->fail('expected MapFileUnavailable');
        } catch (MapFileUnavailable) {
            // expected
        }

        $this->assertNotNull($map->fresh()->last_archive_attempt_at);
    }
}
