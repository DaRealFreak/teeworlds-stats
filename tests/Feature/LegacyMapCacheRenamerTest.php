<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Service\LegacyMapCacheRenamer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LegacyMapCacheRenamerTest extends TestCase
{
    use RefreshDatabase;

    public function test_renames_legacy_files_skips_orphans_and_is_idempotent(): void
    {
        Storage::fake('local');

        $sha = str_repeat('a', 64);
        Map::create(['name' => 'Kobra 4', 'sha256' => $sha]);
        Storage::disk('local')->put('maps/' . $sha . '.map', 'LEGACY');

        $orphan = str_repeat('e', 64); // no matching Map row
        Storage::disk('local')->put('maps/' . $orphan . '.map', 'ORPHAN');

        $renamed = (new LegacyMapCacheRenamer())->run();

        $this->assertSame(1, $renamed);
        Storage::disk('local')->assertExists('maps/Kobra_4_' . $sha . '.map');
        Storage::disk('local')->assertMissing('maps/' . $sha . '.map');
        Storage::disk('local')->assertExists('maps/' . $orphan . '.map');

        $this->assertSame(0, (new LegacyMapCacheRenamer())->run());
    }
}
