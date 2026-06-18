<?php

namespace App\Service;

use App\Models\Map;
use App\Models\MapVersion;
use Illuminate\Support\Facades\Storage;

/**
 * The single entry point for acquiring a map's .map file. Returns the path (relative to its
 * disk) of a cached file, downloading it via the first MapSource that can serve it. Files are
 * keyed by content identity (sha256, or crc for the future UDP path), so identical maps share a
 * cache entry and a re-download only happens after a cache miss.
 *
 * Both map-keyed (ensure) and version-keyed (ensureVersion) acquisition paths are supported;
 * they share a common pathForIdentity() naming convention so a file cached via either path is
 * found by the other.
 */
final class MapFileStore
{
    /**
     * @param MapSource[] $sources tried in order; first non-null wins
     */
    public function __construct(
        private readonly array $sources = [new DdnetHttpMapSource(), new GameServerMapSource()],
        public readonly string $disk = 'local',
        private readonly MapVersionRecorder $versionRecorder = new MapVersionRecorder(),
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
     * Version-keyed acquisition path: fetch and cache the .map file for a specific MapVersion.
     * Cache hit returns the relative path immediately; on a miss the sources' fetchVersion() are
     * tried in order. On success the version's identity is backfilled via MapVersionRecorder and
     * the file is written under the version key.
     *
     * @return string path of the cached .map relative to $this->disk
     * @throws MapFileUnavailable when no source can produce verified bytes
     */
    public function ensureVersion(MapVersion $version): string
    {
        $storage = Storage::disk($this->disk);

        $relative = $this->relativePathForVersion($version);
        if ($relative !== null && $storage->exists($relative)) {
            // A file already on disk has no success event to flip its status, so reconcile it here:
            // this promotes pre-existing files and the migration's `pending` default to `cached`. The
            // inequality keeps a repeat ensureVersion on an already-cached version from writing again.
            if ($version->getAttribute('acquisition_status') !== MapVersion::STATUS_CACHED) {
                $version->markStatus(MapVersion::STATUS_CACHED);
            }

            return $relative;
        }

        foreach ($this->sources as $source) {
            $bytes = $source->fetchVersion($version);
            if ($bytes === null) {
                continue;
            }

            // record() backfills + may MERGE (deleting this row, returning the survivor); adopt the returned
            // row so the cache key reflects the surviving version's identity. It is non-null here (we pass both keys).
            // For versions that already carry a server-reported CRC (UDP sources), trust that CRC for the
            // identity lookup rather than recomputing it from the bytes — the computed sha256 still captures
            // the true content hash. This ensures the existing CRC row gets its sha256 backfilled (so
            // isCachedVersion stays consistent) rather than a duplicate row being created for the new hash.
            $computedCrc = sprintf('%08x', crc32($bytes) & 0xFFFFFFFF);
            $version = $this->versionRecorder->record(
                $version->map,
                hash('sha256', $bytes),
                $version->getAttribute('crc') ?? $computedCrc,
                strlen($bytes),
            ) ?? $version;

            $relative = $this->relativePathForVersion($version);
            if ($relative === null) {
                continue;
            }

            $storage->put($relative, $bytes);

            return $relative;
        }

        throw new MapFileUnavailable();
    }

    /**
     * Path (relative to $this->disk) where this map's .map file is or would be cached, or null when
     * the map has no usable content key. The naming and key logic is owned by MapCachePath::forIdentity.
     */
    public function relativePathFor(Map $map): ?string
    {
        return MapCachePath::forIdentity(
            (string) $map->getAttribute('name'),
            $map->getAttribute('sha256'),
            $map->getAttribute('crc'),
        );
    }

    /**
     * Path (relative to $this->disk) where this version's .map file is or would be cached,
     * or null when the version has no usable content key.
     */
    public function relativePathForVersion(MapVersion $version): ?string
    {
        return MapCachePath::forIdentity(
            (string) $version->map->getAttribute('name'),
            $version->getAttribute('sha256'),
            $version->getAttribute('crc'),
        );
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
     * Whether this version's .map file is already cached on disk.
     */
    public function isCachedVersion(MapVersion $version): bool
    {
        $relative = $this->relativePathForVersion($version);

        return $relative !== null && Storage::disk($this->disk)->exists($relative);
    }

    /**
     * Record a freshly-downloaded keyless map's content identity, setting only the columns that are
     * still null (no-clobber, parallel to the no-clobber refresh in UpdateData::recordServerMap). The derived sha256 is the true
     * content hash of the verified bytes, so it is a valid cache key even for maps DDNet never mirrors.
     * Only the changed columns are written, so any concurrent column updates survive.
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

}
