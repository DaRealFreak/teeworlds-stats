<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\Map;
use App\Models\MapVersion;
use Illuminate\Support\Facades\Storage;

/**
 * Persists a map we downloaded over UDP that turned out to be a different (but self-consistent) map or
 * content version than the one requested. The served bytes are a real map regardless of why we got
 * them, so we record their true identity — keyed off the in-game MAP_CHANGE name — and cache the file.
 *
 * Pure persistence: the caller (GameServerMapSource) owns all acquisition telemetry, so this records
 * no MapAcquisitionAttempted events. It must not construct a MapFileStore (which would build a
 * GameServerMapSource and cycle), so it writes through the shared MapCachePath helper directly.
 */
final class CapturedMapVersionRecorder
{
    public function __construct(
        private readonly string $disk = 'local',
        private readonly MapVersionRecorder $recorder = new MapVersionRecorder(),
    ) {
    }

    /**
     * Record + cache the served map. $wireName is the in-game MAP_CHANGE name; $servedCrcHex is the
     * server-announced crc as 8-hex (equal to crc32 of the verified bytes). Returns the recorded
     * MapVersion, or null only if it could not be keyed.
     */
    public function capture(string $wireName, string $bytes, ?string $servedCrcHex): ?MapVersion
    {
        $map = Map::firstOrCreate(['name' => $wireName]);

        $version = $this->recorder->record(
            $map,
            hash('sha256', $bytes),
            $servedCrcHex ?? sprintf('%08x', crc32($bytes) & 0xFFFFFFFF),
            strlen($bytes),
        );
        if ($version === null) {
            return null;
        }

        $path = MapCachePath::forIdentity(
            (string) $map->getAttribute('name'),
            $version->getAttribute('sha256'),
            $version->getAttribute('crc'),
        );
        $storage = Storage::disk($this->disk);
        if ($path !== null && !$storage->exists($path)) {
            $storage->put($path, $bytes);
        }

        if ($version->getAttribute('acquisition_status') !== MapVersion::STATUS_CACHED) {
            $version->markStatus(MapVersion::STATUS_CACHED);
        }

        return $version;
    }
}
