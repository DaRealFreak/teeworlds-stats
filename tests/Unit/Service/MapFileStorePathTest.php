<?php

namespace Tests\Unit\Service;

use App\Models\Map;
use App\Service\MapFileStore;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MapFileStorePathTest extends TestCase
{
    public function test_relative_path_uses_sanitized_name_prefix_and_hash(): void
    {
        $sha = str_repeat('a', 64);
        $map = (new Map())->forceFill(['name' => 'Kobra 4', 'sha256' => $sha]);

        $this->assertSame('maps/Kobra_4_' . $sha . '.map', (new MapFileStore())->relativePathFor($map));
    }

    public function test_relative_path_falls_back_to_crc_when_no_sha(): void
    {
        $map = (new Map())->forceFill(['name' => 'dm1', 'crc' => 'aabbccdd']);

        $this->assertSame('maps/dm1_aabbccdd.map', (new MapFileStore())->relativePathFor($map));
    }

    public function test_slug_strips_traversal_and_unsafe_characters(): void
    {
        $sha = str_repeat('b', 64);
        $map = (new Map())->forceFill(['name' => '../../etc/pa ss.wd', 'sha256' => $sha]);

        $path = (new MapFileStore())->relativePathFor($map);

        $this->assertSame('maps/etc_pa_ss_wd_' . $sha . '.map', $path);
        $this->assertStringNotContainsString('..', $path);
        $this->assertStringNotContainsString('/etc/', $path);
    }

    public function test_all_unsafe_name_falls_back_to_hash_only_filename(): void
    {
        $sha = str_repeat('c', 64);
        $map = (new Map())->forceFill(['name' => '日本語', 'sha256' => $sha]);

        $this->assertSame('maps/' . $sha . '.map', (new MapFileStore())->relativePathFor($map));
    }

    public function test_relative_path_is_null_for_missing_or_non_hex_key(): void
    {
        $store = new MapFileStore();

        $this->assertNull($store->relativePathFor((new Map())->forceFill(['name' => 'no-key'])));
        $this->assertNull($store->relativePathFor((new Map())->forceFill(['name' => 'evil', 'crc' => '../etc'])));
    }

    public function test_is_cached_reflects_disk_presence(): void
    {
        Storage::fake('local');
        $sha = str_repeat('d', 64);
        $map = (new Map())->forceFill(['name' => 'dm2', 'sha256' => $sha]);
        $store = new MapFileStore();

        $this->assertFalse($store->isCached($map));

        Storage::disk('local')->put('maps/dm2_' . $sha . '.map', 'BYTES');
        $this->assertTrue($store->isCached($map));
    }
}
