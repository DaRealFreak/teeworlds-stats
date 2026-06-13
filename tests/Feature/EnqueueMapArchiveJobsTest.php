<?php

namespace Tests\Feature;

use App\Jobs\ArchiveMap;
use App\Models\Map;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EnqueueMapArchiveJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_enqueues_only_eligible_uncached_maps(): void
    {
        Storage::fake('local');
        Queue::fake();

        $queued = Map::create(['name' => 'queued-map', 'sha256' => str_repeat('a', 64)]); // eligible
        Map::create(['name' => 'no-key']); // excluded: no content key
        Map::create(['name' => 'cooling', 'sha256' => str_repeat('b', 64), 'last_archive_attempt_at' => now()]); // excluded: cooldown

        $cachedSha = str_repeat('c', 64);
        Map::create(['name' => 'cached', 'sha256' => $cachedSha]); // excluded: already on disk
        Storage::disk('local')->put('maps/cached_' . $cachedSha . '.map', 'BYTES');

        $this->artisan('maps:archive-enqueue')->assertSuccessful();

        Queue::assertPushed(ArchiveMap::class, 1);
        Queue::assertPushed(ArchiveMap::class, fn (ArchiveMap $job) => $job->map->is($queued));
    }

    public function test_limit_caps_dispatch_count(): void
    {
        Storage::fake('local');
        Queue::fake();

        Map::create(['name' => 'm1', 'sha256' => str_repeat('a', 64)]);
        Map::create(['name' => 'm2', 'sha256' => str_repeat('b', 64)]);
        Map::create(['name' => 'm3', 'sha256' => str_repeat('c', 64)]);

        $this->artisan('maps:archive-enqueue', ['--limit' => '1'])->assertSuccessful();

        Queue::assertPushed(ArchiveMap::class, 1);
    }
}
