<?php

namespace App\Service;

use App\Models\Map;
use Illuminate\Support\Facades\Storage;

/**
 * The single entry point for acquiring a map's .map file. Returns the path (relative to its
 * disk) of a cached file, downloading it via the first MapSource that can serve it. Files are
 * keyed by content identity (sha256, or crc for the future UDP path), so identical maps share a
 * cache entry and a re-download only happens after a cache miss.
 */
final class MapFileStore
{
    /**
     * @param MapSource[] $sources tried in order; first non-null wins
     */
    public function __construct(
        private readonly array $sources = [new DdnetHttpMapSource()],
        public readonly string $disk = 'local',
    ) {
    }

    /**
     * @return string path of the cached .map relative to $this->disk
     * @throws MapFileUnavailable when no source can produce verified bytes
     */
    public function ensure(Map $map): string
    {
        $key = $map->getAttribute('sha256') ?? $map->getAttribute('crc');
        if ($key === null) {
            throw new MapFileUnavailable();
        }

        $relative = 'maps/' . $key . '.map';
        $storage = Storage::disk($this->disk);
        if ($storage->exists($relative)) {
            return $relative;
        }

        foreach ($this->sources as $source) {
            $bytes = $source->fetch($map);
            if ($bytes !== null) {
                $storage->put($relative, $bytes);

                return $relative;
            }
        }

        throw new MapFileUnavailable();
    }
}
