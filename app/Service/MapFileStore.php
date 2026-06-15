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
        $storage = Storage::disk($this->disk);

        // Fast path: a keyed map whose file is already cached.
        $relative = $this->relativePathFor($map);
        if ($relative !== null && $storage->exists($relative)) {
            return $relative;
        }

        foreach ($this->sources as $source) {
            $bytes = $source->fetch($map);
            if ($bytes === null) {
                continue;
            }

            // Keyless maps have no path until now: derive their content identity from the verified
            // bytes and record it (no-clobber) so relativePathFor() can name the file.
            if ($this->relativePathFor($map) === null) {
                $this->backfillIdentity($map, $bytes);
            }

            $relative = $this->relativePathFor($map);
            if ($relative === null) {
                // Still unkeyable (e.g. a pre-set but non-hex key) — cannot safely name the file.
                continue;
            }

            $storage->put($relative, $bytes);

            return $relative;
        }

        throw new MapFileUnavailable();
    }

    /**
     * Record a freshly-downloaded keyless map's content identity, setting only the columns that are
     * still null (no-clobber, parallel to the no-clobber refresh in UpdateData::recordServerMap). The derived sha256 is the true
     * content hash of the verified bytes, so it is a valid cache key even for maps DDNet never mirrors.
     * Only the changed columns are written, so a concurrently-stamped last_archive_attempt_at survives.
     */
    private function backfillIdentity(Map $map, string $bytes): void
    {
        $updates = [];
        if ($map->getAttribute('sha256') === null) {
            $updates['sha256'] = hash('sha256', $bytes);
        }
        if ($map->getAttribute('crc') === null) {
            $updates['crc'] = sprintf('%08x', crc32($bytes) & 0xFFFFFFFF);
        }
        if ($map->getAttribute('size') === null) {
            $updates['size'] = strlen($bytes);
        }

        if ($updates !== []) {
            $map->update($updates);
        }
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
