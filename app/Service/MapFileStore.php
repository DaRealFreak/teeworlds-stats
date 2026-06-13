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
        private readonly array $sources = [new DdnetHttpMapSource(), new GameServerMapSource()],
        public readonly string $disk = 'local',
    ) {
    }

    /**
     * @return string path of the cached .map relative to $this->disk
     * @throws MapFileUnavailable when no source can produce verified bytes
     */
    public function ensure(Map $map): string
    {
        $relative = $this->relativePathFor($map);
        if ($relative === null) {
            throw new MapFileUnavailable();
        }

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

    /**
     * Path (relative to $this->disk) where this map's .map file is or would be cached, or null when
     * the map has no usable content key. The filename is <sanitized-name>_<sha256|crc>.map: the hash
     * keeps uniqueness and is the path-traversal guard (it must be pure hex); the name is a
     * best-effort, filesystem-safe prefix so the archive is browsable on disk. Lookup never parses
     * the filename back — callers always hold the Map — so a lossy slug is harmless.
     */
    public function relativePathFor(Map $map): ?string
    {
        $key = $map->getAttribute('sha256') ?? $map->getAttribute('crc');
        if ($key === null || !ctype_xdigit($key)) {
            return null;
        }

        $slug = $this->nameSlug((string) $map->getAttribute('name'));
        $prefix = $slug === '' ? '' : $slug . '_';

        return 'maps/' . $prefix . $key . '.map';
    }

    /**
     * Whether this map's .map file is already cached on disk.
     */
    public function isCached(Map $map): bool
    {
        $relative = $this->relativePathFor($map);

        return $relative !== null && Storage::disk($this->disk)->exists($relative);
    }

    /**
     * Filesystem-safe, human-readable slug for a (server-controlled) map name. The whitelist keeps
     * out '/', '.' and whitespace, so the slug can never contain a path separator or '..'; the dash
     * sits last in the class so 0-9-_ is not read as a range. Capped so name + hash stay well under
     * the 255-byte filename limit.
     */
    private function nameSlug(string $name): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '_', $name);

        return rtrim(substr(trim($slug, '_'), 0, 64), '_');
    }
}
