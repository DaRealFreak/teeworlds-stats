<?php

declare(strict_types=1);

namespace Tests\Feature\Service;

use App\Models\Map;
use App\Models\MapVersion;
use App\Service\CapturedMapVersionRecorder;
use App\Service\MapCachePath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CapturedMapVersionRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_a_new_version_and_stores_the_bytes(): void
    {
        Storage::fake('local');
        $bytes = 'DATA' . str_repeat("\x00", 40);
        $crcHex = sprintf('%08x', crc32($bytes) & 0xFFFFFFFF);
        $sha = hash('sha256', $bytes);

        $version = (new CapturedMapVersionRecorder(disk: 'local'))->capture('Gridania', $bytes, $crcHex);

        self::assertNotNull($version);
        self::assertSame('Gridania', $version->map->name);
        self::assertSame($crcHex, $version->crc);
        self::assertSame($sha, $version->sha256);
        self::assertSame(MapVersion::STATUS_CACHED, $version->acquisition_status);

        $path = MapCachePath::forIdentity('Gridania', $sha, $crcHex);
        Storage::disk('local')->assertExists($path);
        self::assertSame($bytes, Storage::disk('local')->get($path));
    }

    public function test_reuses_existing_map_and_is_idempotent(): void
    {
        Storage::fake('local');
        $map = Map::create(['name' => 'ctf4_old']);
        $bytes = 'DATA' . str_repeat("\x01", 40);
        $crcHex = sprintf('%08x', crc32($bytes) & 0xFFFFFFFF);

        $recorder = new CapturedMapVersionRecorder(disk: 'local');
        $first = $recorder->capture('ctf4_old', $bytes, $crcHex);
        $second = $recorder->capture('ctf4_old', $bytes, $crcHex);

        self::assertTrue($first->is($second), 'a re-capture returns the same version row');
        self::assertSame(1, MapVersion::where('map_id', $map->id)->count());
        self::assertSame(1, Map::where('name', 'ctf4_old')->count());
    }
}
