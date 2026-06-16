<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapVersion;
use App\Service\MapFileStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MapFileStoreStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_ensure_version_reconciles_an_existing_file_to_cached(): void
    {
        // A version whose file is already on disk but whose status is still the migration's `pending`
        // default: the cache-hit early-return must reconcile the status to `cached`, since no success
        // event fires for a file the store never downloaded.
        $bytes = 'DATA' . str_repeat("\x00", 64);
        $sha = hash('sha256', $bytes);
        $map = Map::create(['name' => 'Aim']);
        $version = MapVersion::create([
            'map_id' => $map->id,
            'sha256' => $sha,
            'size' => strlen($bytes),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $store = new MapFileStore();
        Storage::disk('local')->put($store->relativePathForVersion($version), $bytes);

        // The status starts at the migration's DB-side `pending` default (not set on the in-memory row).
        $this->assertSame(MapVersion::STATUS_PENDING, $version->fresh()->acquisition_status);

        $relative = $store->ensureVersion($version);

        $this->assertNotEmpty($relative);
        $this->assertSame(MapVersion::STATUS_CACHED, $version->fresh()->acquisition_status);
    }
}
